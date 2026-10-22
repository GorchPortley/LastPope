<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Filament\Resources\OrderIssueResource;
use App\Models\OrderIssue;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** The problem reports of an order, each linking to its case in the "Problem reports" queue. */
class IssuesRelationManager extends RelationManager
{
    protected static string $relationship = 'issues';

    protected static ?string $title = 'Problem reports';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with(['order', 'seller.sellerProfile']))
            ->recordUrl(fn (OrderIssue $record): string => OrderIssueResource::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),
                Tables\Columns\TextColumn::make('seller.name')
                    ->label('Seller')
                    ->description(fn (OrderIssue $record): ?string => $record->seller?->sellerProfile?->shop_name),
                Tables\Columns\TextColumn::make('reason')
                    ->formatStateUsing(fn ($state): string => OrderIssueResource::reason($state)->shortLabel())
                    ->description(fn (OrderIssue $record): ?string => $record->isDispute() ? 'Bank dispute' : null),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => OrderIssueResource::status($state)->label())
                    ->color(fn ($state): string => OrderIssueResource::status($state)->color()),
                Tables\Columns\TextColumn::make('resolution')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => OrderIssueResource::resolution($state)->label())
                    ->color(fn ($state): string => OrderIssueResource::resolution($state)->color())
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('refunded_cents')
                    ->label('Refunded')
                    ->formatStateUsing(fn ($state, OrderIssue $record): string => money((int) $state, $record->order->currency))
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('created_at')->label('Reported')->dateTime('M j, Y H:i'),
            ])
            ->actions([
                Tables\Actions\Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-m-eye')
                    ->url(fn (OrderIssue $record): string => OrderIssueResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('No problem reports')
            ->emptyStateDescription('The buyer has not reported a problem with this order.');
    }
}
