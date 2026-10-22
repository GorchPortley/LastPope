<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\ShipStoreParcelAction;
use App\Models\Order;
use App\Services\Marketplace\Data\RefundResult;
use App\Services\Marketplace\RefundService;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ShipStoreParcelAction::make(Actions\Action::class),
            Actions\Action::make('refundOrder')
                ->label('Refund order')
                ->icon('heroicon-m-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (Order $record): bool => static::canRefund($record))
                ->requiresConfirmation()
                ->modalHeading(fn (Order $record): string => 'Refund order '.$record->number.'?')
                ->modalDescription('Everything that was not refunded yet is returned to the buyer through Stripe. Payouts already transferred to sellers are taken back and the buyer loses access to refunded downloads. This cannot be undone.')
                ->modalSubmitActionLabel('Refund')
                ->form([
                    Textarea::make('reason')->label('Reason (optional, kept in the Stripe metadata)')->maxLength(500)->rows(2),
                ])
                ->action(function (array $data, Order $record): void {
                    static::refund(fn () => app(RefundService::class)->refundOrder(auth()->user(), $record, $data['reason'] ?? null));

                    $this->refreshFormData(['status']);
                }),
        ];
    }

    /** Paid orders that still have something to give back. */
    public static function canRefund(Order $order): bool
    {
        return $order->paid_at !== null
            && filled($order->stripe_payment_intent_id)
            && $order->sellerOrders()->whereNotIn('status', ['refunded', 'cancelled'])->exists();
    }

    /**
     * Runs a refund and tells the admin what happened, including a payout that could not be taken back.
     *
     * @param  callable(): RefundResult  $refund
     */
    public static function refund(callable $refund): ?RefundResult
    {
        try {
            $result = $refund();
        } catch (InvalidOrderStateException $e) {
            Notification::make()->danger()->title('Nothing was refunded')->body($e->getMessage())->send();

            return null;
        } catch (PaymentGatewayException $e) {
            report($e);
            Notification::make()->danger()->title('Stripe refused the refund')->body($e->getMessage())->persistent()->send();

            return null;
        }

        Notification::make()
            ->success()
            ->title('Refunded '.money($result->amountCents, $result->order->currency))
            ->body('Refund '.($result->refundId ?? '').' for order '.$result->order->number.'. The buyer was notified.')
            ->send();

        if ($result->hasFailedReversals()) {
            Notification::make()
                ->warning()
                ->title('A payout could not be taken back')
                ->body('The buyer is refunded, but Stripe did not reverse the transfer for '
                    .collect($result->reversalFailed)->map(fn ($sellerOrder) => 'seller order #'.$sellerOrder->getKey())->implode(', ')
                    .'. Use "Retry transfer reversal" on the seller order.')
                ->persistent()
                ->send();
        }

        return $result;
    }
}
