<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Orders the user placed as a buyer, read-only; open one for refunds and shipping. */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Orders placed';

    public function table(Table $table): Table
    {
        $status = fn ($state): OrderStatus => $state instanceof OrderStatus ? $state : OrderStatus::from((string) $state);

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->searchable()->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state): string => $status($state)->label())
                    ->color(fn ($state): string => $status($state)->color()),
                Tables\Columns\TextColumn::make('total_cents')->label('Total')->formatStateUsing(fn ($state, Order $record): string => money((int) $state, $record->currency)),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ]);
    }
}
