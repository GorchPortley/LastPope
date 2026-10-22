<?php

namespace App\Filament\Resources\SupplierOrderResource;

use App\Enums\SupplierOrderStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Filament\Forms\MoneyInput;
use App\Mail\PurchaseOrderMail;
use App\Models\SupplierOrder;
use App\Services\Marketplace\PurchaseOrderService;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What an admin does with a purchase order (docs/PLAN.md 8.3), as Filament actions. Every action calls
 * PurchaseOrderService with the signed-in admin as the actor; the service authorizes and checks the state again.
 * The same actions serve the purchase order page (header actions) and the queue (table actions): pass the action
 * class, \Filament\Actions\Action or \Filament\Tables\Actions\Action.
 *
 *     PurchaseOrderActions::send(Action::class)
 */
final class PurchaseOrderActions
{
    /** Carriers suggested while typing (free text is fine). */
    public const CARRIERS = ['USPS', 'UPS', 'FedEx', 'DHL', 'Royal Mail', 'Canada Post', 'Australia Post', 'Deutsche Post', 'PostNL', 'La Poste'];

    /** @var array<int, string> */
    private const OPEN = ['pending', 'sent', 'confirmed', 'problem'];

    /** Send (or send again) the purchase order by e-mail. */
    public static function send(string $action): object
    {
        return $action::make('send')
            ->label(fn (SupplierOrder $record): string => $record->status === SupplierOrderStatus::SENT ? 'Send again' : 'Send email')
            ->icon('heroicon-m-paper-airplane')
            ->visible(fn (SupplierOrder $record): bool => $record->canBeSent() && filled($record->supplier?->order_email))
            ->requiresConfirmation()
            ->modalHeading(fn (SupplierOrder $record): string => 'E-mail '.$record->number.' to the supplier?')
            ->modalDescription(fn (SupplierOrder $record): string => 'The purchase order is e-mailed to '.$record->supplier->order_email
                .', with the lines as a CSV attachment. Replies go to '.PurchaseOrderMail::replyToAddress().'.'
                .($record->status === SupplierOrderStatus::SENT ? ' It was sent already; this sends it again.' : ''))
            ->modalSubmitActionLabel('Send email')
            ->action(fn (SupplierOrder $record) => static::attempt(
                fn () => app(PurchaseOrderService::class)->send(auth()->user(), $record),
                'Purchase order sent',
                fn (SupplierOrder $record): string => $record->number.' was e-mailed to '.$record->supplier->order_email.'.',
                $record,
            ));
    }

    /** The order was placed in the supplier's web portal (or by phone): PENDING -> SENT with their reference. */
    public static function placedManually(string $action): object
    {
        return $action::make('placedManually')
            ->label('Mark placed manually')
            ->icon('heroicon-m-check-badge')
            ->color('gray')
            ->visible(fn (SupplierOrder $record): bool => $record->status === SupplierOrderStatus::PENDING)
            ->modalHeading(fn (SupplierOrder $record): string => 'Mark '.$record->number.' as placed')
            ->modalDescription('You placed this order in the supplier\'s web portal or by phone. Enter the order number they gave you.')
            ->modalSubmitActionLabel('Mark as placed')
            ->form([
                TextInput::make('reference')->label('Supplier\'s order reference')->required()->maxLength(100),
            ])
            ->action(fn (array $data, SupplierOrder $record) => static::attempt(
                fn () => app(PurchaseOrderService::class)->markPlacedManually(auth()->user(), $record, (string) $data['reference']),
                'Marked as placed',
                fn (SupplierOrder $record): string => $record->number.' is with the supplier as '.$data['reference'].'.',
                $record,
            ));
    }

    /** The supplier acknowledged the order. */
    public static function confirm(string $action): object
    {
        return $action::make('confirm')
            ->label('Confirm')
            ->icon('heroicon-m-check-circle')
            ->color('success')
            ->visible(fn (SupplierOrder $record): bool => in_array($record->status, [SupplierOrderStatus::SENT, SupplierOrderStatus::PROBLEM], true))
            ->modalHeading(fn (SupplierOrder $record): string => 'Supplier confirmed '.$record->number)
            ->modalDescription('The supplier acknowledged the order. Add their order number if they gave one.')
            ->modalSubmitActionLabel('Confirm')
            ->fillForm(fn (SupplierOrder $record): array => ['reference' => $record->supplier_reference])
            ->form([
                TextInput::make('reference')->label('Supplier\'s order reference (optional)')->maxLength(100),
            ])
            ->action(fn (array $data, SupplierOrder $record) => static::attempt(
                fn () => app(PurchaseOrderService::class)->confirm(auth()->user(), $record, $data['reference'] ?? null),
                'Purchase order confirmed',
                null,
                $record,
            ));
    }

    /** What the supplier charges: line costs, shipping, dropship fee. */
    public static function recordCosts(string $action): object
    {
        return $action::make('recordCosts')
            ->label('Record supplier costs')
            ->icon('heroicon-m-banknotes')
            ->color('gray')
            ->visible(fn (SupplierOrder $record): bool => $record->status !== SupplierOrderStatus::CANCELLED)
            ->modalHeading(fn (SupplierOrder $record): string => 'Costs of '.$record->number)
            ->modalDescription('What the supplier charges us. These numbers feed the margin report.')
            ->modalSubmitActionLabel('Save costs')
            ->modalWidth('2xl')
            ->fillForm(fn (SupplierOrder $record): array => [
                'shipping_cost' => $record->shipping_cost_cents,
                'dropship_fee' => $record->dropship_fee_cents,
                'unit_costs' => $record->activeItems()->pluck('unit_cost_cents', 'id')->all(),
            ])
            ->form(fn (SupplierOrder $record): array => [
                ...$record->activeItems()->get()->map(fn ($line) => MoneyInput::make('unit_costs.'.$line->getKey())
                    ->label(($line->quantity > 1 ? $line->quantity.' x ' : '').$line->title.($line->supplier_sku ? ' ('.$line->supplier_sku.')' : '').': unit cost')
                    ->required()
                    ->default($line->unit_cost_cents))->all(),
                MoneyInput::make('shipping_cost')->label('Shipping the supplier charges us')->nullable()->helperText('Leave empty while you do not know it yet.'),
                MoneyInput::make('dropship_fee')->label('Dropship fee')->required(),
            ])
            ->action(function (array $data, SupplierOrder $record) {
                $unitCosts = collect($data['unit_costs'] ?? [])->map(fn ($cents) => (int) $cents)->all();

                static::attempt(
                    fn () => app(PurchaseOrderService::class)->recordSupplierCosts(
                        auth()->user(),
                        $record,
                        isset($data['shipping_cost']) ? (int) $data['shipping_cost'] : null,
                        $unitCosts,
                        isset($data['dropship_fee']) ? (int) $data['dropship_fee'] : null,
                    ),
                    'Costs saved',
                    null,
                    $record,
                );
            });
    }

    /** The supplier shipped: carrier + tracking become a parcel and the buyer is told. */
    public static function markShipped(string $action): object
    {
        return $action::make('markShipped')
            ->label('Mark shipped')
            ->icon('heroicon-m-truck')
            ->visible(fn (SupplierOrder $record): bool => in_array($record->status, [SupplierOrderStatus::SENT, SupplierOrderStatus::CONFIRMED, SupplierOrderStatus::PROBLEM], true))
            ->modalHeading(fn (SupplierOrder $record): string => 'The supplier shipped '.$record->number)
            ->modalDescription('Enter the carrier and tracking number the supplier gave you. The buyer gets an e-mail with them (never the supplier\'s name).')
            ->modalSubmitActionLabel('Save and notify buyer')
            ->form([
                TextInput::make('carrier')->required()->maxLength(100)->datalist(self::CARRIERS),
                TextInput::make('tracking_number')->required()->maxLength(100),
                TextInput::make('tracking_url')->label('Tracking link (optional)')->url()->maxLength(500)->placeholder('https://'),
            ])
            ->action(fn (array $data, SupplierOrder $record) => static::attempt(
                fn () => app(PurchaseOrderService::class)->markShipped(auth()->user(), $record, $data['carrier'], $data['tracking_number'], $data['tracking_url'] ?? null),
                'Marked as shipped',
                fn (SupplierOrder $record): string => 'The buyer has been notified about the parcel of '.$record->number.'.',
                $record,
            ));
    }

    /** Cancel the whole purchase order (the supplier agreed). Does not refund the buyer. */
    public static function cancel(string $action): object
    {
        return $action::make('cancel')
            ->label('Cancel purchase order')
            ->icon('heroicon-m-x-circle')
            ->color('danger')
            ->visible(fn (SupplierOrder $record): bool => in_array($record->status->value, self::OPEN, true))
            ->requiresConfirmation()
            ->modalHeading(fn (SupplierOrder $record): string => 'Cancel '.$record->number.'?')
            ->modalDescription('Use this once the supplier agreed to cancel. Every line is cancelled. The buyer is not refunded automatically: refund the order separately if it will not be delivered.')
            ->modalSubmitActionLabel('Cancel purchase order')
            ->form([
                Textarea::make('note')->label('Note (optional)')->maxLength(2000)->rows(3),
            ])
            ->action(fn (array $data, SupplierOrder $record) => static::attempt(
                fn () => app(PurchaseOrderService::class)->cancel(auth()->user(), $record, $data['note'] ?? null),
                'Purchase order cancelled',
                null,
                $record,
            ));
    }

    /** Needs attention: a back-order, a wrong item... */
    public static function flagProblem(string $action): object
    {
        return $action::make('flagProblem')
            ->label('Flag a problem')
            ->icon('heroicon-m-exclamation-triangle')
            ->color('warning')
            ->visible(fn (SupplierOrder $record): bool => ! in_array($record->status, [SupplierOrderStatus::CANCELLED, SupplierOrderStatus::SHIPPED], true))
            ->modalHeading(fn (SupplierOrder $record): string => 'Flag a problem with '.$record->number)
            ->modalDescription('The purchase order moves to the Problem tab with your note, until you confirm, ship or cancel it.')
            ->modalSubmitActionLabel('Flag problem')
            ->form([
                Textarea::make('note')->label('What is wrong?')->required()->maxLength(2000)->rows(4),
            ])
            ->action(fn (array $data, SupplierOrder $record) => static::attempt(
                fn () => app(PurchaseOrderService::class)->flagProblem($record, (string) $data['note'], auth()->user()),
                'Flagged as a problem',
                null,
                $record,
            ));
    }

    /** The lines as CSV for the supplier's portal or spreadsheet. */
    public static function downloadCsv(string $action): object
    {
        return $action::make('downloadCsv')
            ->label('Download CSV')
            ->icon('heroicon-m-arrow-down-tray')
            ->color('gray')
            ->action(function (SupplierOrder $record): StreamedResponse {
                \Illuminate\Support\Facades\Gate::forUser(auth()->user())->authorize('view', $record);
                $csv = app(PurchaseOrderService::class)->toCsv($record);

                return response()->streamDownload(function () use ($csv) {
                    echo $csv;
                }, $record->number.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
            });
    }

    /**
     * Run a service call and tell the admin what happened. Returns whether it worked.
     *
     * @param  callable(): mixed  $call
     * @param  (callable(SupplierOrder): string)|null  $body  the notification text, given the refreshed purchase order
     */
    public static function attempt(callable $call, string $success, ?callable $body = null, ?SupplierOrder $record = null): bool
    {
        try {
            $call();
        } catch (InvalidOrderStateException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();

            return false;
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Please check your input')->body((string) collect($e->errors())->flatten()->first())->send();

            return false;
        }

        $notification = Notification::make()->success()->title($success);

        if ($body !== null && $record !== null) {
            $notification->body($body($record->fresh(['supplier'])));
        }

        $notification->send();

        return true;
    }
}
