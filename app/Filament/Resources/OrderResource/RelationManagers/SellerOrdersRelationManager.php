<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Filament\Resources\OrderIssueResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Models\SellerOrder;
use App\Services\Marketplace\RefundService;
use App\Services\Marketplace\ShipmentService;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use App\Filament\Forms\MoneyInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

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
            ->modifyQueryUsing(fn ($query) => $query->with(['seller.sellerProfile', 'order', 'items', 'activeIssue']))
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
                Tables\Columns\TextColumn::make('problem')
                    ->label('Problem')
                    ->state(fn (SellerOrder $record): ?string => $record->activeIssue?->status->label() ?? ($record->issues()->exists() ? 'Resolved' : null))
                    ->badge()
                    ->color(fn (SellerOrder $record): string => $record->activeIssue?->status->color() ?? 'success')
                    ->url(fn (SellerOrder $record): ?string => ($issue = $record->activeIssue ?? $record->issues()->first()) ? OrderIssueResource::getUrl('view', ['record' => $issue]) : null)
                    ->placeholder('-'),
            ])
            ->actions([
                // Platform sellers (SDLabs' store) have no seller dashboard of their own: any admin ships for them.
                Tables\Actions\Action::make('markShipped')
                    ->label(fn (SellerOrder $record): string => $record->status === SellerOrderStatus::SHIPPED ? 'Update tracking' : 'Mark shipped')
                    ->icon('heroicon-m-truck')
                    ->color('primary')
                    ->visible(fn (SellerOrder $record): bool => in_array($record->status, [SellerOrderStatus::AWAITING_SHIPMENT, SellerOrderStatus::PARTIALLY_SHIPPED, SellerOrderStatus::SHIPPED], true)
                        && Gate::allows('ship', $record))
                    ->modalHeading(fn (SellerOrder $record): string => 'Ship '.$record->order->number)
                    ->modalDescription('The buyer gets an e-mail with these details.')
                    ->modalSubmitActionLabel('Save and notify buyer')
                    ->fillForm(fn (SellerOrder $record): array => [
                        'carrier' => $record->carrier,
                        'tracking_number' => $record->tracking_number,
                        'tracking_url' => $record->tracking_url,
                    ])
                    ->form([
                        TextInput::make('carrier')->required()->maxLength(100)
                            ->datalist(['USPS', 'UPS', 'FedEx', 'DHL', 'Royal Mail', 'Canada Post', 'Australia Post', 'Deutsche Post', 'PostNL', 'La Poste']),
                        TextInput::make('tracking_number')->required()->maxLength(100),
                        TextInput::make('tracking_url')->label('Tracking link (optional)')->url()->maxLength(500)->placeholder('https://'),
                    ])
                    ->action(function (array $data, SellerOrder $record): void {
                        try {
                            app(ShipmentService::class)->markShipped(auth()->user(), $record, $data['carrier'], $data['tracking_number'], $data['tracking_url'] ?? null);
                        } catch (InvalidOrderStateException $e) {
                            Notification::make()->danger()->title('Could not ship this order')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Marked as shipped')->body('The buyer has been notified.')->send();
                    }),
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
                Tables\Actions\Action::make('refundPartially')
                    ->label('Partial refund')
                    ->icon('heroicon-m-receipt-percent')
                    ->color('warning')
                    ->visible(fn (SellerOrder $record): bool => $record->order->paid_at !== null
                        && ! in_array($record->status, [SellerOrderStatus::REFUNDED, SellerOrderStatus::CANCELLED, SellerOrderStatus::PENDING], true)
                        && $record->refundableCents() > 1)
                    ->modalHeading(fn (SellerOrder $record): string => 'Refund part of '.$record->order->number)
                    ->modalDescription(fn (SellerOrder $record): string => 'Less than '.money($record->refundableCents(), $record->order->currency).' of this seller\'s items and shipping. The platform fee stays: a payout not made yet shrinks by the amount, one already made is partly taken back.')
                    ->modalSubmitActionLabel('Refund this amount')
                    ->form(fn (SellerOrder $record): array => [
                        MoneyInput::make('amount')->label('Amount to refund')->required()->minValue(0.01)->maxValue(max(0, ($record->refundableCents() - 1) / 100)),
                        Textarea::make('reason')->label('Reason (optional)')->maxLength(500)->rows(2),
                    ])
                    ->action(function (array $data, SellerOrder $record): void {
                        ViewOrder::refund(fn () => app(RefundService::class)->refundSellerOrderPartially(auth()->user(), $record, (int) $data['amount'], $data['reason'] ?? null));
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

    /** Refunded (fully or partly) after a payout was transferred, but the reversal never went through. */
    public static function needsReversal(SellerOrder $sellerOrder): bool
    {
        if (! $sellerOrder->isTransferred() || blank($sellerOrder->stripe_transfer_id)) {
            return false;
        }

        return $sellerOrder->reversal_pending_cents > 0
            || ($sellerOrder->isRefunded() && blank($sellerOrder->stripe_reversal_id));
    }
}
