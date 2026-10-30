<?php

namespace App\Filament\Resources\DesignResource\RelationManagers;

use App\Filament\Resources\Concerns\CatalogueActions;
use App\Filament\Resources\DriverResource;
use App\Models\DesignDriver;
use App\Models\Driver;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use FilamentTiptapEditor\TiptapEditor;

/** The drivers of a design (`design_driver` rows), with the same fields and file folders as the dashboard. */
class ComponentsRelationManager extends RelationManager
{
    protected static string $relationship = 'components';

    protected static ?string $title = 'Drivers in this design';

    protected static ?string $modelLabel = 'driver';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        $dir = fn (string $sub) => fn (Get $get): string => $this->getOwnerRecord()->storageDirectory(str($get('position') ?: 'Unsorted')->upper().'/'.$sub);

        return $form
            ->columns(2)
            ->schema([
                Forms\Components\Select::make('driver_id')
                    ->label('Driver')
                    ->relationship('driver', 'model')
                    ->getOptionLabelFromRecordUsing(fn (Driver $record): string => $record->brand.' '.$record->model.($record->active ? '' : ' (inactive)'))
                    ->searchable(['brand', 'model'])
                    ->required(),
                Forms\Components\Select::make('position')
                    ->options(['LF' => 'LF', 'LMF' => 'LMF', 'MF' => 'MF', 'HMF' => 'HMF', 'HF' => 'HF', 'Other' => 'Other'])
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('quantity')->numeric()->minValue(1)->default(1)->required(),
                Forms\Components\TextInput::make('air_volume')->numeric()->default(0),
                Forms\Components\TextInput::make('low_frequency')->numeric()->suffix('Hz'),
                Forms\Components\TextInput::make('high_frequency')->numeric()->suffix('Hz'),
                TiptapEditor::make('description')
                    ->columnSpanFull()
                    ->disk('public')
                    ->directory(fn (): string => $this->getOwnerRecord()->storageDirectory('Attachments/Description Images')),
                Forms\Components\KeyValue::make('specifications')
                    ->label('Measured specifications')
                    ->helperText('What the designer measured, not the datasheet.')
                    ->columnSpanFull()
                    ->default(array_fill_keys(CatalogueActions::specKeys(), ''))
                    ->keyLabel('Parameter')
                    ->valueLabel('Value'),
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
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('driver'))
            ->columns([
                Tables\Columns\TextColumn::make('position'),
                Tables\Columns\TextColumn::make('driver.model')
                    ->label('Driver')
                    ->formatStateUsing(fn (DesignDriver $record): string => $record->driver ? $record->driver->brand.' '.$record->driver->model : 'Deleted driver')
                    ->url(fn (DesignDriver $record): ?string => $record->driver_id ? DriverResource::getUrl('edit', ['record' => $record->driver_id]) : null),
                Tables\Columns\TextColumn::make('quantity'),
                Tables\Columns\TextColumn::make('range')
                    ->label('Range')
                    ->state(fn (DesignDriver $record): string => ($record->low_frequency ?? '?').' - '.($record->high_frequency ?? '?').' Hz'),
                Tables\Columns\TextColumn::make('air_volume')->label('Air volume'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }
}
