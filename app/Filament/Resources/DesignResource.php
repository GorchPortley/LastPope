<?php

namespace App\Filament\Resources;

use App\Enums\DesignCategoryEnum;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\Concerns\CatalogueActions;
use App\Filament\Resources\DesignResource\Pages;
use App\Filament\Resources\DesignResource\RelationManagers;
use App\Models\Design;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use FilamentTiptapEditor\Enums\TiptapOutput;
use FilamentTiptapEditor\TiptapEditor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Every column of `designs` for admins. Files go where the dashboard puts them (designs/{storage_key}/..., source files
 * on the private disk, pictures and rich-text images on the public disk), so both editors see the same files.
 * The drivers of a design, its snapshots, who has access and the listings built from it are relation managers.
 */
class DesignResource extends Resource
{
    protected static ?string $model = Design::class;

    protected static ?string $navigationIcon = 'phosphor-speaker-hifi';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        $dir = fn (string $sub) => fn (Get $get): string => 'designs/'.($get('storage_key') ?: 'unassigned').'/'.$sub;

        return $form
            ->schema([
                Forms\Components\Section::make('Owner and status')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->label('Designer (owner)')
                            ->relationship('designer', 'name')
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
                            ->label('Published')
                            ->onColor('success')
                            ->helperText('Admins can publish anything; you are warned when it cannot be sold as it is.'),
                        Forms\Components\Toggle::make('official')
                            ->helperText('Shown as official (manufacturer designs).'),
                        Forms\Components\TextInput::make('forum_slug')
                            ->label('Forum discussion')
                            ->maxLength(255)
                            ->helperText('The Flarum discussion ("{id}-{slug}"). Filled in automatically; clear it to have a new one created.'),
                    ]),

                Forms\Components\Section::make('Details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')->required()->maxLength(255),
                        Forms\Components\TextInput::make('tag')->label('Tagline')->maxLength(255),
                        Forms\Components\Select::make('category')
                            ->options(collect(DesignCategoryEnum::cases())->mapWithKeys(fn ($case) => [$case->value => $case->value])->all())
                            ->required(),
                        MoneyInput::make('price_cents')->label('Price')->default(0)->required()->helperText('0 = free.'),
                        MoneyInput::make('build_cost_cents')->label('Build cost')->default(0)->required(),
                        Forms\Components\TextInput::make('impedance')->numeric()->suffix('Ω'),
                        Forms\Components\TextInput::make('power')->numeric()->suffix('W'),
                    ]),

                Forms\Components\Section::make('Text')
                    ->schema([
                        TiptapEditor::make('summary')
                            ->profile('default')
                            ->disk('public')
                            ->directory($dir('Attachments/Summary Images'))
                            ->output(TiptapOutput::Html),
                        TiptapEditor::make('description')
                            ->disk('public')
                            ->directory($dir('Attachments/Description Images')),
                        Forms\Components\KeyValue::make('bill_of_materials')
                            ->label('Bill of materials')
                            ->keyLabel('Part')
                            ->valueLabel('Quantity / source'),
                    ]),

                Forms\Components\Section::make('Advanced')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\Textarea::make('description_css')
                            ->label('Page styles (CSS)')
                            ->helperText('Optional, for designers who know CSS. Applies only to your summary, description and driver notes. Rules for anything else on the page, @import, url() and fixed positioning are removed. Leave empty to use the site\'s style.')
                            ->rows(8)
                            ->maxLength(20000)
                            ->extraInputAttributes(['class' => 'font-mono text-sm', 'spellcheck' => 'false']),
                    ]),

                Forms\Components\Section::make('Files')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        Forms\Components\FileUpload::make('card_image')
                            ->label('Design images (public)')
                            ->multiple()
                            ->reorderable()
                            ->image()
                            ->disk('public')
                            ->visibility('public')
                            ->directory($dir('Attachments/Display Images')),
                        Forms\Components\FileUpload::make('frd_files')
                            ->label('Response previews (FRD)')
                            ->multiple()
                            ->preserveFilenames()
                            ->disk('private')
                            ->visibility('private')
                            ->directory($dir('Attachments/Display Responses')),
                        Forms\Components\FileUpload::make('enclosure_files')
                            ->label('Enclosure files')
                            ->multiple()
                            ->preserveFilenames()
                            ->disk('private')
                            ->visibility('private')
                            ->directory($dir('Enclosure')),
                        Forms\Components\FileUpload::make('electronic_files')
                            ->label('Electronics files')
                            ->multiple()
                            ->preserveFilenames()
                            ->disk('private')
                            ->visibility('private')
                            ->directory($dir('Electronics')),
                        Forms\Components\FileUpload::make('design_other_files')
                            ->label('Other design files')
                            ->multiple()
                            ->preserveFilenames()
                            ->disk('private')
                            ->visibility('private')
                            ->directory($dir('Other Files')),
                    ]),

                Forms\Components\Section::make('Record')
                    ->columns(3)
                    ->collapsed()
                    ->visibleOn('edit')
                    ->schema([
                        Forms\Components\Placeholder::make('id')->label('ID')->content(fn (?Design $record): string => (string) $record?->id),
                        Forms\Components\Placeholder::make('created_at')->label('Created')->content(fn (?Design $record): string => (string) $record?->created_at),
                        Forms\Components\Placeholder::make('updated_at')->label('Updated')->content(fn (?Design $record): string => (string) $record?->updated_at),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('designer')->withCount(['components', 'snapshots']))
            ->columns([
                Tables\Columns\ImageColumn::make('card_image')
                    ->label('')
                    ->disk('public')
                    ->limit(1)
                    ->square(),
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Design $record): ?string => $record->tag),
                Tables\Columns\TextColumn::make('designer.name')
                    ->label('Designer')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Design $record): ?string => $record->user_id ? UserResource::getUrl('edit', ['record' => $record->user_id]) : null),
                Tables\Columns\TextColumn::make('category')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('price_cents')
                    ->label('Price')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => (int) $state > 0 ? money((int) $state) : 'Free'),
                Tables\Columns\ToggleColumn::make('active')
                    ->label('Published')
                    ->afterStateUpdated(fn (Design $record, $state) => static::warnAboutBlockers($record, (bool) $state)),
                Tables\Columns\IconColumn::make('official')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('components_count')->label('Drivers')->sortable(),
                Tables\Columns\TextColumn::make('snapshots_count')->label('Snapshots')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Published'),
                Tables\Filters\TernaryFilter::make('official'),
                Tables\Filters\SelectFilter::make('category')
                    ->options(collect(DesignCategoryEnum::cases())->mapWithKeys(fn ($case) => [$case->value => $case->value])->all()),
                Tables\Filters\TernaryFilter::make('paid')
                    ->label('Price')
                    ->trueLabel('Paid')
                    ->falseLabel('Free')
                    ->queries(
                        true: fn (Builder $query) => $query->paid(),
                        false: fn (Builder $query) => $query->free(),
                    ),
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Designer')
                    ->relationship('designer', 'name')
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ActionGroup::make([
                    CatalogueActions::viewOnSite(Tables\Actions\Action::class),
                    CatalogueActions::snapshot(Tables\Actions\Action::class),
                    CatalogueActions::transferOwnership(Tables\Actions\Action::class),
                    Tables\Actions\DeleteAction::make()->modalDescription('Deletes the design, its driver rows, snapshots and every file. Buyers lose access.'),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Admins may publish anything, but are told when it cannot be sold as it is (paid without a snapshot, owner without payouts). */
    public static function warnAboutBlockers(Design $design, bool $published): void
    {
        if ($published && ($blockers = $design->publishBlockers()) !== []) {
            Notification::make()
                ->warning()
                ->title('Published despite open issues')
                ->body(implode(' ', $blockers))
                ->persistent()
                ->send();
        }
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ComponentsRelationManager::class,
            RelationManagers\SnapshotsRelationManager::class,
            RelationManagers\EntitlementsRelationManager::class,
            RelationManagers\ListingsRelationManager::class,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'tag'];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDesigns::route('/'),
            'create' => Pages\CreateDesign::route('/create'),
            'edit' => Pages\EditDesign::route('/{record}/edit'),
        ];
    }
}
