<?php

namespace App\Filament\Resources\OrderIssueResource\Pages;

use App\Enums\IssueResolution;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\OrderIssueResource;
use App\Filament\Resources\OrderResource;
use App\Models\OrderIssue;
use App\Services\Marketplace\IssueService;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

/**
 * One case: facts, conversation and the admin's decisions. Everything goes through IssueService with the signed-in admin
 * as the actor (the service authorizes and checks the state again).
 */
class ViewOrderIssue extends ViewRecord
{
    protected static string $resource = OrderIssueResource::class;

    public function getTitle(): string
    {
        return 'Problem report #'.$this->record->getKey().' - order '.$this->record->order->number;
    }

    protected function getHeaderActions(): array
    {
        $service = fn (): IssueService => app(IssueService::class);
        $can = fn (OrderIssue $record, string $action): bool => $service()->can(auth()->user(), $record, $action);
        $money = fn (OrderIssue $record): string => money($record->refundableCents(), $record->order->currency);

        return [
            Actions\Action::make('reply')
                ->label('Reply')
                ->icon('heroicon-m-chat-bubble-left-ellipsis')
                ->visible(fn (OrderIssue $record): bool => $can($record, 'reply'))
                ->modalHeading('Reply to buyer and seller')
                ->modalDescription('Both of them see this message and are notified.')
                ->modalSubmitActionLabel('Send')
                ->form([Textarea::make('body')->label('Message')->required()->maxLength(5000)->rows(5)])
                ->action(fn (array $data, OrderIssue $record) => $this->run(fn () => $service()->reply(auth()->user(), $record, $data['body']), 'Message sent')),
            Actions\Action::make('note')
                ->label('Internal note')
                ->icon('heroicon-m-pencil-square')
                ->color('gray')
                ->modalHeading('Add an internal note')
                ->modalDescription('Only admins see this. Nobody is notified.')
                ->modalSubmitActionLabel('Save note')
                ->form([Textarea::make('body')->label('Note')->required()->maxLength(5000)->rows(4)])
                ->action(fn (array $data, OrderIssue $record) => $this->run(fn () => $service()->internalNote(auth()->user(), $record, $data['body']), 'Note saved')),
            Actions\ActionGroup::make([
                Actions\Action::make('fullRefund')
                    ->label('Full refund')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (OrderIssue $record): bool => $can($record, 'decide') && $record->refundableCents() > 0)
                    ->requiresConfirmation()
                    ->modalHeading('Refund the buyer in full?')
                    ->modalDescription(fn (OrderIssue $record): string => $money($record).' (items and shipping of this seller) goes back to the buyer through Stripe. A payout that was not made yet is cancelled, one that was made is taken back from the seller. This cannot be undone.')
                    ->modalSubmitActionLabel('Refund in full')
                    ->form([Textarea::make('note')->label('Note for buyer and seller (optional)')->maxLength(2000)->rows(3)])
                    ->action(fn (array $data, OrderIssue $record) => $this->run(fn () => $service()->adminResolve(auth()->user(), $record, IssueResolution::FULL_REFUND, null, $data['note'] ?? null), 'Refunded in full')),
                Actions\Action::make('partialRefund')
                    ->label('Partial refund')
                    ->icon('heroicon-m-receipt-percent')
                    ->color('warning')
                    ->visible(fn (OrderIssue $record): bool => $can($record, 'decide') && $record->refundableCents() > 1)
                    ->modalHeading('Refund part of the order')
                    ->modalDescription(fn (OrderIssue $record): string => 'Less than '.$money($record).'. The platform fee stays; a pending payout shrinks by this amount, a payout already made is partly taken back.')
                    ->modalSubmitActionLabel('Refund this amount')
                    ->form(fn (OrderIssue $record): array => [
                        MoneyInput::make('amount')
                            ->label('Amount to refund')
                            ->required()
                            ->minValue(0.01)
                            ->maxValue(max(0, ($record->refundableCents() - 1) / 100)),
                        Textarea::make('note')->label('Note for buyer and seller (optional)')->maxLength(2000)->rows(3),
                    ])
                    ->action(fn (array $data, OrderIssue $record) => $this->run(fn () => $service()->adminResolve(auth()->user(), $record, IssueResolution::PARTIAL_REFUND, (int) $data['amount'], $data['note'] ?? null), 'Partly refunded')),
                Actions\Action::make('noAction')
                    ->label('No action')
                    ->icon('heroicon-m-check')
                    ->color('gray')
                    ->visible(fn (OrderIssue $record): bool => $can($record, 'decide'))
                    ->requiresConfirmation()
                    ->modalHeading('Close without a refund?')
                    ->modalDescription('The buyer is not refunded and the seller\'s payout continues as planned. Both are told.')
                    ->modalSubmitActionLabel('Close the case')
                    ->form([Textarea::make('note')->label('Explanation for buyer and seller (optional)')->maxLength(2000)->rows(3)])
                    ->action(fn (array $data, OrderIssue $record) => $this->run(fn () => $service()->adminResolve(auth()->user(), $record, IssueResolution::NO_ACTION, null, $data['note'] ?? null), 'Case closed')),
            ])
                ->label('Decide')
                ->icon('heroicon-m-scale')
                ->color('primary')
                ->button()
                ->visible(fn (OrderIssue $record): bool => $can($record, 'decide')),
            Actions\Action::make('recover')
                ->label('Recover from seller')
                ->icon('heroicon-m-banknotes')
                ->color('danger')
                ->visible(fn (OrderIssue $record): bool => $can($record, 'recover'))
                ->requiresConfirmation()
                ->modalHeading('Take the money back from the seller?')
                ->modalDescription(fn (OrderIssue $record): string => $record->sellerOrder->isTransferred()
                    ? 'The bank dispute was lost. The seller\'s transfer for this order is reversed through Stripe.'
                    : 'The bank dispute was lost. The seller was not paid out yet, so the payout is cancelled.')
                ->modalSubmitActionLabel('Recover')
                ->action(fn (OrderIssue $record) => $this->run(fn () => $service()->recoverFromSeller(auth()->user(), $record), 'Recovered from the seller')),
            Actions\Action::make('order')
                ->label('Open order')
                ->icon('heroicon-m-receipt-percent')
                ->color('gray')
                ->url(fn (OrderIssue $record): string => OrderResource::getUrl('view', ['record' => $record->order])),
        ];
    }

    /** Run a service call, show what happened, and refresh the page data. */
    private function run(callable $call, string $success): void
    {
        try {
            $call();
        } catch (InvalidOrderStateException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();

            return;
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Please check your input')->body(collect($e->errors())->flatten()->first())->send();

            return;
        } catch (PaymentGatewayException $e) {
            report($e);
            Notification::make()->danger()->title('Stripe refused')->body($e->getMessage())->persistent()->send();

            return;
        }

        Notification::make()->success()->title($success)->send();

        $this->record->refresh();
        $this->refreshFormData(['status', 'resolution', 'refunded_cents', 'resolved_at', 'recovered_cents']);
    }
}
