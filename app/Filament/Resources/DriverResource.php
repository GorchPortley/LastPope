<?php

namespace App\Filament\Resources;

use App\Enums\DriverCategoryEnum;
use App\Exports\SpecificationsExport;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\Concerns\CatalogueActions;
use App\Filament\Resources\DriverResource\Pages;
use App\Filament\Resources\DriverResource\RelationManagers;
use App\Models\DesignDriver;
use App\Models\Driver;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use FilamentTiptapEditor\TiptapEditor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Every column of `drivers` for admins. Files go where the dashboard puts them (drivers/{storage_key}/...): the
 * manufacturer's measurement files on the private disk, pictures and rich-text images on the public disk.
 */
class DriverResource extends Resource
{
    protected static ?string $model = Driver::class;

    protected static ?string $navigationIcon = 'phosphor-speaker-simple-high';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        $dir = fn (string $sub) => fn (Get $get): string => 'drivers/'.($get('storage_key') ?: 'unassigned').'/'.$sub;

        return $form
            ->schema([
                Forms\Components\Section::make('Owner and status')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->label('Added by (owner)')
                            ->relationship('creator', 'name')
                            ->searchable(['name', 'username', 'email'])
                            ->required()
                            ->disabledOn('edit')
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Use "Transfer ownership" at the top to change it.' : null),
                        Forms\Components\TextInput::make('storage_key')
                            ->label('Storage key')
                            ->default(fn (): string => (string) Str::uuid())
                            ->readOnly()
                            ->required()
                            ->helperText('Folder of the files. It never changes.'),
                        Forms\Components\Toggle::make('active')
                            ->onColor('success')
                            ->helperText('Public and selectable in designs.'),
                        Forms\Components\Toggle::make('official')
                            ->helperText('Added by the manufacturer.'),
                        Forms\Components\TextInput::make('forum_slug')
                            ->label('Forum discussion')
                            ->maxLength(255)
                            ->helperText('The Flarum discussion ("{id}-{slug}"). Filled in automatically; clear it to have a new one created.'),
                    ]),

                Forms\Components\Section::make('Details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('brand')
                            ->required()
                            ->maxLength(255)
                            ->datalist(fn () => DB::table('drivers')->distinct()->orderBy('brand')->pluck('brand')->all()),
                        Forms\Components\TextInput::make('model')->required()->maxLength(255),
                        Forms\Components\TextInput::make('tag')->maxLength(255),
                        Forms\Components\Select::make('category')
                            ->options(collect(DriverCategoryEnum::cases())->mapWithKeys(fn ($case) => [$case->value => $case->value])->all())
                            ->required(),
                        Forms\Components\TextInput::make('size')
                            ->numeric()
                            ->required()
                            ->suffix('inch')
                            ->helperText('Whole inches: the column stores an integer.'),
                        Forms\Components\TextInput::make('impedance')->required()->maxLength(255)->suffix('Ω'),
                        Forms\Components\TextInput::make('power')->numeric()->required()->suffix('W'),
                        MoneyInput::make('msrp_cents')->label('MSRP')->nullable(),
                        Forms\Components\TextInput::make('link')->url()->maxLength(255)->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Text and specs')
                    ->schema([
                        Forms\Components\TextInput::make('summary')->maxLength(65535),
                        TiptapEditor::make('description')
                            ->disk('public')
                            ->directory($dir('Attachments/Description Images')),
                        Forms\Components\KeyValue::make('factory_specs')
                            ->label('Factory specs (datasheet)')
                            ->default(array_fill_keys(CatalogueActions::specKeys(), ''))
                            ->keyLabel('Parameter')
                            ->valueLabel('Value'),
                    ]),

                Forms\Components\Section::make('Files (from the manufacturer)')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        Forms\Components\FileUpload::make('card_image')
                            ->label('Driver images (public)')
                            ->multiple()
                            ->reorderable()
                            ->image()
                            ->disk('public')
                            ->visibility('public')
                            ->directory($dir('Attachments/Display Images')),
                        Forms\Components\FileUpload::make('frequency_files')
                            ->label('Frequency measurements')
                            ->multiple()->preserveFilenames()->disk('private')->visibility('private')
                            ->directory($dir('Frequency')),
                        Forms\Components\FileUpload::make('impedance_files')
                            ->label('Impedance measurements')
                            ->multiple()->preserveFilenames()->disk('private')->visibility('private')
                            ->directory($dir('Impedance')),
                        Forms\Components\FileUpload::make('other_files')
                            ->label('Other files')
                            ->multiple()->preserveFilenames()->disk('private')->visibility('private')
                            ->directory($dir('Other')),
                    ]),

                Forms\Components\Section::make('Record')
                    ->columns(3)
                    ->collapsed()
                    ->visibleOn('edit')
                    ->schema([
                        Forms\Components\Placeholder::make('id')->label('ID')->content(fn (?Driver $record): string => (string) $record?->id),
                        Forms\Components\Placeholder::make('created_at')->label('Created')->content(fn (?Driver $record): string => (string) $record?->created_at),
                        Forms\Components\Placeholder::make('updated_at')->label('Updated')->content(fn (?Driver $record): string => (string) $record?->updated_at),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $categories = collect(DriverCategoryEnum::cases())->mapWithKeys(fn ($case) => [$case->value => $case->value])->all();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('creator')->withCount(['designs', 'snapshots']))
            ->columns([
                Tables\Columns\ImageColumn::make('card_image')->label('')->disk('public')->limit(1)->square(),
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('brand')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('model')->searchable()->sortable()->description(fn (Driver $record): ?string => $record->tag),
                Tables\Columns\TextColumn::make('category')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('size')->suffix('"')->sortable(),
                Tables\Columns\TextColumn::make('impedance')->suffix(' Ω')->sortable(),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Owner')
                    ->searchable()
                    ->url(fn (Driver $record): ?string => $record->user_id ? UserResource::getUrl('edit', ['record' => $record->user_id]) : null),
                Tables\Columns\ToggleColumn::make('active'),
                Tables\Columns\IconColumn::make('official')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('designs_count')->label('In designs')->sortable(),
                Tables\Columns\TextColumn::make('snapshots_count')->label('Snapshots')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('msrp_cents')->label('MSRP')->formatStateUsing(fn ($state): string => $state !== null ? money((int) $state) : '-')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('active'),
                Tables\Filters\TernaryFilter::make('official'),
                Tables\Filters\SelectFilter::make('category')->options($categories),
                Tables\Filters\SelectFilter::make('brand')
                    ->options(fn (): array => Driver::query()->distinct()->orderBy('brand')->pluck('brand', 'brand')->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('user_id')->label('Owner')->relationship('creator', 'name')->searchable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ActionGroup::make([
                    CatalogueActions::viewOnSite(Tables\Actions\Action::class),
                    CatalogueActions::snapshot(Tables\Actions\Action::class),
                    static::collectDataAction(Tables\Actions\Action::class),
                    CatalogueActions::transferOwnership(Tables\Actions\Action::class),
                    Tables\Actions\DeleteAction::make()->modalDescription('Deletes the driver, its snapshots and files. Designs that use it lose the link.'),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activate')
                        ->icon('heroicon-m-check')
                        ->action(fn ($records) => $records->each->update(['active' => true]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Deactivate')
                        ->icon('heroicon-m-x-mark')
                        ->action(fn ($records) => $records->each->update(['active' => false]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * "Collect data": a spreadsheet of what every designer measured for this driver (the manufacturer's dashboard export,
     * for admins). Only published designs unless asked otherwise.
     *
     * @param  class-string<Tables\Actions\Action|\Filament\Actions\Action>  $class
     */
    public static function collectDataAction(string $class): Tables\Actions\Action|\Filament\Actions\Action
    {
        return $class::make('collectData')
            ->label('Collect data')
            ->icon('phosphor-table')
            ->color('gray')
            ->form([
                Forms\Components\Toggle::make('include_unpublished')->label('Include unpublished designs'),
            ])
            ->action(fn (array $data, Driver $record): BinaryFileResponse => static::specExport($record, (bool) ($data['include_unpublished'] ?? false)));
    }

    public static function specExport(Driver $driver, bool $includeUnpublished = false): BinaryFileResponse
    {
        $rows = $driver->designs()
            ->when(! $includeUnpublished, fn ($query) => $query->whereHas('design', fn ($q) => $q->where('active', true)))
            ->get()
            ->map(fn (DesignDriver $component): array => [
                'position' => $component->position,
                'quantity' => $component->quantity,
                'low_frequency' => $component->low_frequency,
                'high_frequency' => $component->high_frequency,
                'air_volume' => $component->air_volume,
                ...collect(CatalogueActions::specKeys())->mapWithKeys(fn (string $key): array => [$key => $component->specifications[$key] ?? null])->all(),
            ]);

        return Excel::download(new SpecificationsExport($rows), "{$driver->brand} {$driver->model}-SDLabs-export-".now()->format('Y-m-d').'.xlsx');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DesignsRelationManager::class,
            RelationManagers\SnapshotsRelationManager::class,
            RelationManagers\ListingsRelationManager::class,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['brand', 'model'];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDrivers::route('/'),
            'create' => Pages\CreateDriver::route('/create'),
            'edit' => Pages\EditDriver::route('/{record}/edit'),
        ];
    }
}
