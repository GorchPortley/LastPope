<?php

namespace App\Filament\Resources\Concerns;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

/** The snapshots (download ZIPs) of a design or driver: list, download, build a new one, delete. */
abstract class SnapshotsRelationManager extends RelationManager
{
    protected static string $relationship = 'snapshots';

    protected static ?string $title = 'Snapshots';

    /** design-snapshots.download or driver-snapshots.download */
    abstract protected function downloadRoute(): string;

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('version')->searchable(),
                Tables\Columns\TextColumn::make('snapshot_name')->label('File'),
                Tables\Columns\TextColumn::make('file_size')
                    ->label('Size')
                    ->formatStateUsing(fn ($state): string => $state ? Number::fileSize((int) $state) : '-'),
                Tables\Columns\TextColumn::make('created_at')->label('Built')->dateTime()->sortable(),
            ])
            ->headerActions([
                CatalogueActions::snapshot(Tables\Actions\Action::class, fn () => $this->getOwnerRecord()),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->url(fn (Model $record): string => route($this->downloadRoute(), $record)),
                Tables\Actions\DeleteAction::make()->modalDescription('Deletes the ZIP. Buyers keep the other versions.'),
            ]);
    }
}
