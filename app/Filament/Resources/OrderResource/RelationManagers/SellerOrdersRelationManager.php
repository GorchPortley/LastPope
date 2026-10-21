<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Models\SellerOrder;
use App\Services\Marketplace\RefundService;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SellerOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'sellerOrders';

    protected static ?string $title = 'Seller orders';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $money = fn ($state, SellerOrder $record): string => money((int) $state, $record->order->currency);
        $status = fn ($state): SellerOrderStatus => $state instanceof SellerOrderStatus ? $state : SellerOrderStatus::from((string) $state);

        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with(['seller.sellerProfile', 'order', 'items']))
            ->columns([
                Tables\Columns\TextColumn::make('seller.name')
                    ->label('Seller')
                    ->description(fn (SellerOrder $record): ?string => $record->seller?->sellerProfile?->shop_name),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $status($state)->label())
                    ->color(fn ($state): string => $status($state)->color()),
                Tables\Columns\TextColumn::make('subtotal_cents')->label('Items')->formatStateUsing($money),
                Tables\Columns\TextColumn::make('shipping_cents')->label('Shipping')->formatStateUsing($money),
                Tables\Columns\TextColumn::make('platform_fee_cents')->label('Fee')->formatStateUsing($money),
                Tables\Columns\TextColumn::make('payout_cents')->label('Payout')->formatStateUsing($money),
                Tables\Columns\TextColumn::make('transferred_at')->label('Transferred')->dateTime()->placeholder('no'),
                Tables\Columns\TextColumn::make('tracking_number')
                    ->label('Tracking')
                    ->description(fn (SellerOrder $record): ?string => $record->carrier)
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('refunded_cents')
                    ->label('Refunded')
                    ->formatStateUsing($money)
                    ->placeholder('-')
                    ->description(fn (SellerOrder $record): ?string => static::needsReversal($record) ? 'payout reversal pending' : null),
            ])
            ->actions([
                Tables\Actions\Action::make('refundSellerOrder')
                    ->label('Refund')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (SellerOrder $record): bool => $record->order->paid_at !== null
                        && ! in_array($record->status, [SellerOrderStatus::REFUNDED, SellerOrderStatus::CANCELLED, SellerOrderStatus::PENDING], true))
                    ->requiresConfirmation()
                    ->modalHeading(fn (SellerOrder $record): string => 'Refund this seller\'s part of '.$record->order->number.'?')
                    ->modalDescription('Items and shipping of this seller are returned to the buyer; the other sellers of the order are not touched. A payout already transferred is taken back from the seller.')
                    ->modalSubmitActionLabel('Refund')
                    ->form([
                        Textarea::make('reason')->label('Reason (optional)')->maxLength(500)->rows(2),
                    ])
                    ->action(function (array $data, SellerOrder $record): void {
                        ViewOrder::refund(fn () => app(RefundService::class)->refundSellerOrder(auth()->user(), $record, $data['reason'] ?? null));
                    }),
                Tables\Actions\Action::make('retryReversal')
                    ->label('Retry transfer reversal')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->visible(fn (SellerOrder $record): bool => static::needsReversal($record))
                    ->requiresConfirmation()
                    ->modalDescription('Asks Stripe again to take the payout back from the seller\'s account.')
                    ->action(function (SellerOrder $record): void {
                        try {
                            $done = app(RefundService::class)->retryTransferReversal(auth()->user(), $record);
                        } catch (InvalidOrderStateException|PaymentGatewayException $e) {
                            report($e);
                            Notification::make()->danger()->title('The transfer could not be reversed')->body($e->getMessage())->persistent()->send();

                            return;
                        }

                        $done
                            ? Notification::make()->success()->title('Transfer reversed')->body('The payout was taken back from the seller.')->send()
                            : Notification::make()->warning()->title('Nothing to reverse')->send();
                    }),
            ]);
    }

    /** Refunded after a payout was transferred, but the reversal never went through. */
    public static function needsReversal(SellerOrder $sellerOrder): bool
    {
        return $sellerOrder->isRefunded()
            && $sellerOrder->isTransferred()
            && filled($sellerOrder->stripe_transfer_id)
            && blank($sellerOrder->stripe_reversal_id);
    }
}
