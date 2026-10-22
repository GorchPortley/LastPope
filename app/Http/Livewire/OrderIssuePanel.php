<?php

namespace App\Http\Livewire;

use App\Enums\IssueResolution;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Filament\Forms\MoneyInput;
use App\Models\OrderIssue;
use App\Services\Marketplace\IssueService;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The problem-report panel: the thread, the actions the signed-in person may take (reply, make / accept / decline an
 * offer, return tracking, withdraw, escalate) and the reply form. Used on the shared issue page and on the order pages.
 * Everything goes through IssueService, which re-checks who may do what; the buttons only mirror IssueService::can().
 *
 * After every change the component dispatches `issue-changed` so a host page can refresh itself.
 */
class OrderIssuePanel extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public OrderIssue $issue;

    /** @var array<string, mixed>|null the reply form */
    public ?array $data = [];

    public function mount(OrderIssue $issue): void
    {
        Gate::authorize('view', $issue);

        $this->issue = $issue;
        $this->form->fill(['body' => null, 'photos' => [], 'internal' => false]);
    }

    public function render()
    {
        Gate::authorize('view', $this->issue);

        $this->issue->refresh()->load(['order', 'sellerOrder.items', 'buyer', 'seller.sellerProfile', 'messages.author']);

        return view('livewire.order-issue-panel', [
            'service' => app(IssueService::class),
            'user' => auth()->user(),
        ]);
    }

    // --- Reply ----------------------------------------------------------------------------------------------------

    public function form(Form $form): Form
    {
        $admin = (bool) auth()->user()?->isPlatformSeller();

        return $form
            ->schema([
                Textarea::make('body')
                    ->label($admin ? 'Message or internal note' : 'Write a message')
                    ->placeholder($admin ? 'Visible to the buyer and the seller, unless you tick "Internal note".' : 'Say what you want the other side to know...')
                    ->rows(3)
                    ->maxLength(5000),
                FileUpload::make('photos')
                    ->label('Photos (optional)')
                    ->helperText('Up to '.(int) config('marketplace.issue_max_photos', 5).' pictures, '.round((int) config('marketplace.issue_photo_max_kb', 8192) / 1024).' MB each. Only the people on this problem (and our team) can see them.')
                    ->multiple()
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->maxFiles((int) config('marketplace.issue_max_photos', 5))
                    ->maxSize((int) config('marketplace.issue_photo_max_kb', 8192))
                    ->storeFiles(false),
                Toggle::make('internal')
                    ->label('Internal note (only admins see it, nobody is notified)')
                    ->visible($admin),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        $state = $this->form->getState();
        $user = auth()->user();
        $photos = $this->uploads($state['photos'] ?? []);
        $service = app(IssueService::class);

        $this->guard(function () use ($service, $user, $state, $photos) {
            if ($user->isPlatformSeller() && ($state['internal'] ?? false)) {
                $service->internalNote($user, $this->issue, (string) ($state['body'] ?? ''));
            } else {
                $service->reply($user, $this->issue, (string) ($state['body'] ?? ''), $photos);
            }
        }, 'Message sent');

        $this->form->fill(['body' => null, 'photos' => [], 'internal' => false]);
    }

    // --- Actions ---------------------------------------------------------------------------------------------------

    public function offerAction(): Action
    {
        return Action::make('offer')
            ->visible(fn (): bool => $this->allows('makeOffer'))
            ->label(fn (): string => $this->issue->hasPendingOffer() ? 'Change my offer' : 'Make an offer')
            ->icon('heroicon-m-banknotes')
            ->modalHeading('Offer the buyer a solution')
            ->modalDescription('The buyer can accept it (it is paid at once) or decline it. Your payout for this order is reduced by what you refund.')
            ->modalWidth('lg')
            ->modalSubmitActionLabel('Send offer')
            ->form(fn (): array => [
                Select::make('type')
                    ->label('What do you offer?')
                    ->options(fn (): array => collect(IssueResolution::offerTypes())
                        ->reject(fn (IssueResolution $type) => $type === IssueResolution::RETURN_REFUND && ! $this->issue->sellerOrder->hasPhysicalItems())
                        ->mapWithKeys(fn (IssueResolution $type) => [$type->value => match ($type) {
                            IssueResolution::FULL_REFUND => 'A full refund (keep the goods)',
                            IssueResolution::PARTIAL_REFUND => 'A partial refund (keep the goods)',
                            IssueResolution::RETURN_REFUND => 'A full refund once the buyer sends the goods back',
                        }])->all())
                    ->required()
                    ->live()
                    ->default(IssueResolution::FULL_REFUND->value),
                MoneyInput::make('amount')
                    ->label('Amount to refund')
                    ->helperText(fn (): string => 'Less than '.money($this->issue->refundableCents(), $this->issue->order->currency).' (items and shipping).')
                    ->visible(fn ($get): bool => $get('type') === IssueResolution::PARTIAL_REFUND->value)
                    ->required(fn ($get): bool => $get('type') === IssueResolution::PARTIAL_REFUND->value),
                Textarea::make('note')->label('Message to the buyer (optional)')->rows(3)->maxLength(2000),
            ])
            ->action(function (array $data): void {
                $type = IssueResolution::from($data['type']);

                $this->guard(fn () => app(IssueService::class)->makeOffer(
                    auth()->user(),
                    $this->issue,
                    $type,
                    $type === IssueResolution::PARTIAL_REFUND ? (int) $data['amount'] : null,
                    $data['note'] ?? null,
                ), 'Offer sent', 'The buyer has been notified.');
            });
    }

    public function acceptAction(): Action
    {
        return Action::make('accept')
            ->visible(fn (): bool => $this->allows('acceptOffer'))
            ->label('Accept the offer')
            ->icon('heroicon-m-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Accept this offer?')
            ->modalDescription(fn (): string => 'You accept '.$this->issue->offerLabel().'. '.(
                $this->issue->offer_type === IssueResolution::RETURN_REFUND
                    ? 'You then send the goods back and add the tracking number here; the refund is paid when the seller confirms they arrived.'
                    : 'The refund is paid right away and the problem is closed.'
            ))
            ->modalSubmitActionLabel('Accept')
            ->action(fn () => $this->guard(fn () => app(IssueService::class)->acceptOffer(auth()->user(), $this->issue), 'Offer accepted'));
    }

    public function declineAction(): Action
    {
        return Action::make('decline')
            ->visible(fn (): bool => $this->allows('declineOffer'))
            ->label('Decline')
            ->icon('heroicon-m-x-mark')
            ->color('gray')
            ->modalHeading('Decline this offer?')
            ->modalDescription('The problem stays open. Tell the seller what you would accept.')
            ->modalWidth('lg')
            ->modalSubmitActionLabel('Decline offer')
            ->form([
                Textarea::make('note')->label('Why? (optional)')->rows(3)->maxLength(2000),
            ])
            ->action(fn (array $data) => $this->guard(fn () => app(IssueService::class)->declineOffer(auth()->user(), $this->issue, $data['note'] ?? null), 'Offer declined'));
    }

    public function returnTrackingAction(): Action
    {
        return Action::make('returnTracking')
            ->visible(fn (): bool => $this->allows('addReturnTracking'))
            ->label(fn (): string => filled($this->issue->return_tracking_number) ? 'Update return tracking' : 'Add return tracking')
            ->icon('heroicon-m-truck')
            ->modalHeading('Tell the seller how you sent the goods back')
            ->modalDescription('Use a tracked service and keep your receipt until the refund is paid.')
            ->modalWidth('lg')
            ->modalSubmitActionLabel('Save and notify seller')
            ->fillForm(fn (): array => [
                'carrier' => $this->issue->return_carrier,
                'tracking_number' => $this->issue->return_tracking_number,
                'tracking_url' => $this->issue->return_tracking_url,
            ])
            ->form([
                TextInput::make('carrier')->label('Carrier')->required()->maxLength(100)
                    ->datalist(['USPS', 'UPS', 'FedEx', 'DHL', 'Royal Mail', 'Canada Post', 'Australia Post', 'Deutsche Post', 'PostNL', 'La Poste']),
                TextInput::make('tracking_number')->label('Tracking number')->required()->maxLength(100),
                TextInput::make('tracking_url')->label('Tracking link (optional)')->url()->maxLength(500)->placeholder('https://'),
            ])
            ->action(fn (array $data) => $this->guard(
                fn () => app(IssueService::class)->addReturnTracking(auth()->user(), $this->issue, $data['carrier'], $data['tracking_number'], $data['tracking_url'] ?? null),
                'Return tracking saved',
                'The seller has been notified.',
            ));
    }

    public function confirmReturnAction(): Action
    {
        return Action::make('confirmReturn')
            ->visible(fn (): bool => $this->allows('confirmReturnReceived'))
            ->label('The goods arrived: refund the buyer')
            ->icon('heroicon-m-arrow-uturn-left')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Refund the buyer?')
            ->modalDescription(fn (): string => 'Confirm that you got the goods back. '.money($this->issue->refundableCents(), $this->issue->order->currency).' is refunded to the buyer and your payout for this order is reduced by it.')
            ->modalSubmitActionLabel('Confirm and refund')
            ->action(fn () => $this->guard(fn () => app(IssueService::class)->confirmReturnReceived(auth()->user(), $this->issue), 'Refund issued'));
    }

    public function withdrawAction(): Action
    {
        return Action::make('withdraw')
            ->visible(fn (): bool => $this->allows('withdraw'))
            ->label('Withdraw')
            ->icon('heroicon-m-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Withdraw this report?')
            ->modalDescription('Use this when the problem is solved. The seller is paid as planned and you cannot reopen this report (you can report a new problem while the time to report one is open).')
            ->modalSubmitActionLabel('Withdraw')
            ->action(fn () => $this->guard(fn () => app(IssueService::class)->withdraw(auth()->user(), $this->issue), 'Report withdrawn'));
    }

    public function escalateAction(): Action
    {
        return Action::make('escalate')
            ->visible(fn (): bool => $this->allows('escalate'))
            ->label('Ask '.config('app.name').' to decide')
            ->icon('heroicon-m-scale')
            ->color('warning')
            ->modalHeading('Ask '.config('app.name').' to step in?')
            ->modalDescription('Our team reads the whole thread and decides: full refund, partial refund or no action. Both of you can keep adding messages and photos.')
            ->modalWidth('lg')
            ->modalSubmitActionLabel('Escalate')
            ->form([
                Textarea::make('note')->label('What should we know? (optional)')->rows(3)->maxLength(2000),
            ])
            ->action(fn (array $data) => $this->guard(fn () => app(IssueService::class)->escalate(auth()->user(), $this->issue, $data['note'] ?? null), 'Escalated', 'The '.config('app.name').' team has been notified.'));
    }

    // --- Internals -------------------------------------------------------------------------------------------------

    /** The action is possible for the signed-in person right now (IssueService::can: policy and state). */
    private function allows(string $action): bool
    {
        return app(IssueService::class)->can(auth()->user(), $this->issue, $action);
    }

    /**
     * Run a service call and tell the person what happened: refusals (state, validation, Stripe) become red
     * notifications instead of error pages; a missing permission stays a 403.
     */
    private function guard(callable $call, string $success, ?string $body = null): void
    {
        try {
            $call();
        } catch (AuthorizationException $e) {
            abort(403);
        } catch (InvalidOrderStateException $e) {
            Notification::make()->danger()->title('That did not work')->body($e->getMessage())->send();

            return;
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Please check your input')->body(collect($e->errors())->flatten()->first())->send();

            return;
        } catch (PaymentGatewayException $e) {
            report($e);
            Notification::make()->danger()->title('Stripe refused the refund')->body('Nothing was changed. Try again in a moment.')->persistent()->send();

            return;
        }

        Notification::make()->success()->title($success)->body($body)->send();
        $this->dispatch('issue-changed');
    }

    /**
     * @param  array<int|string, mixed>  $files
     * @return array<int, UploadedFile>
     */
    private function uploads(array $files): array
    {
        return array_values(array_filter($files, fn ($file) => $file instanceof UploadedFile));
    }
}
