<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverResource\Pages;
use App\Filament\Resources\DriverResource\RelationManagers;
use App\Models\Driver;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DriverResource extends Resource
{
    protected static ?string $model = Driver::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ToggleColumn::make('active')
                    ->afterStateUpdated(function (Driver $record, $state): void {
                        // Admins may publish anything, but they are told when it could not be sold as it is
                        // (paid without a snapshot, seller without a payout account).
                        if ($state && ($blockers = $record->publishBlockers()) !== []) {
                            Notification::make()
                                ->warning()
                                ->title('Published despite open issues')
                                ->body(implode(' ', $blockers))
                                ->persistent()
                                ->send();
                        }
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('id')
                ->searchable(),
                Tables\Columns\TextColumn::make('user_id')
                ->searchable(),
                Tables\Columns\TextColumn::make('brand')
                ->searchable(),
                Tables\Columns\TextColumn::make('model')
                ->searchable(),
                Tables\Columns\TextColumn::make('category')
                ->searchable(),
                Tables\Columns\TextColumn::make('size')
                ->searchable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
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
