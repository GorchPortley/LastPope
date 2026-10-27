<?php

namespace App\Support\EmailPreviews;

use App\Enums\DiscrepancyKind;
use App\Enums\IssueResolution;
use App\Enums\IssueStatus;
use App\Mail\PurchaseOrderMail;
use App\Models\StripeDiscrepancy;
use App\Models\User;
use App\Notifications\DisputeOpened;
use App\Notifications\IssueEscalated;
use App\Notifications\IssueMessagePosted;
use App\Notifications\IssueOfferAnswered;
use App\Notifications\IssueOfferMade;
use App\Notifications\IssueOpened;
use App\Notifications\IssueResolved;
use App\Notifications\NewSale;
use App\Notifications\OrderReceipt;
use App\Notifications\OrderRefunded;
use App\Notifications\OrderShipped;
use App\Notifications\PayoutSent;
use App\Notifications\PurchaseOrdersToSend;
use App\Notifications\SaleCancelled;
use App\Notifications\StripeDifferencesFound;
use Closure;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail as LaravelVerifyEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use Wave\Notifications\VerifyEmail as WaveVerifyEmail;

/**
 * Every e-mail the app sends, rendered with realistic made-up data for the admin "Email previews" page
 * (App\Filament\Pages\EmailPreviews): the notifications with a mail channel (app/Notifications), the supplier purchase
 * order (app/Mail) and the account mails that Laravel and Wave send (verify e-mail, reset password).
 *
 * The data (SampleData) is created with the real order code inside a database transaction that is always rolled back,
 * so previews leave nothing behind and need no demo data. Not listed, because they are not sent as e-mail: the
 * database-only notifications (DesignSaved, SnapshotReady, TestNotification) and the forum's own mails, which Flarum
 * sends itself with its own templates.
 */
class EmailPreviews
{
    /**
     * key => [source class, group, label, audience, description, builder]. The builder gets the sample data and returns
     * [Notification|Mailable, notifiable]; it may change the sample problem report first (SampleData::issue()).
     *
     * @return array<string, array{source: class-string, group: string, label: string, audience: string, description: string, build: Closure(SampleData): array{0: Notification|Mailable, 1: object}}>
     */
    public function definitions(): array
    {
        return [
            // --- Orders, to the buyer
            'order_receipt_parcels' => [
                'source' => OrderReceipt::class,
                'group' => 'Orders (to the buyer)', 'label' => 'Order receipt, several parcels', 'audience' => 'Buyer',
                'description' => 'A paid store order: one item ships from SDLabs, one separately.',
                'build' => fn (SampleData $d) => [new OrderReceipt($d->storeOrder), $d->buyer],
            ],
            'order_receipt_digital' => [
                'source' => OrderReceipt::class,
                'group' => 'Orders (to the buyer)', 'label' => 'Order receipt, digital download', 'audience' => 'Buyer',
                'description' => 'A paid order of a design: the files are in the library.',
                'build' => fn (SampleData $d) => [new OrderReceipt($d->digitalOrder), $d->buyer],
            ],
            'order_shipped_parcels' => [
                'source' => OrderShipped::class,
                'group' => 'Orders (to the buyer)', 'label' => 'Order shipped, parcel 1 of 2', 'audience' => 'Buyer',
                'description' => 'The own-stock parcel left; the supplier\'s parcel is still to come.',
                'build' => fn (SampleData $d) => [new OrderShipped($d->storeSale, $d->firstParcel), $d->buyer],
            ],
            'order_shipped' => [
                'source' => OrderShipped::class,
                'group' => 'Orders (to the buyer)', 'label' => 'Order shipped, one parcel', 'audience' => 'Buyer',
                'description' => 'A marketplace seller shipped the order.',
                'build' => fn (SampleData $d) => [new OrderShipped($d->sale, $d->sale->shipments()->first()), $d->buyer],
            ],
            'order_refunded' => [
                'source' => OrderRefunded::class,
                'group' => 'Orders (to the buyer)', 'label' => 'Refund', 'audience' => 'Buyer',
                'description' => 'A refund for a marketplace order.',
                'build' => fn (SampleData $d) => [new OrderRefunded($d->saleOrder, $d->sale->subtotal_cents, $d->sale, 'The seller agreed that the item was not as described.'), $d->buyer],
            ],

            // --- Sales, to the seller
            'new_sale' => [
                'source' => NewSale::class,
                'group' => 'Sales (to the seller)', 'label' => 'New sale', 'audience' => 'Seller',
                'description' => 'A marketplace seller sold something: items, fee, payout and where to ship.',
                'build' => fn (SampleData $d) => [new NewSale($d->sale), $d->seller],
            ],
            'sale_cancelled' => [
                'source' => SaleCancelled::class,
                'group' => 'Sales (to the seller)', 'label' => 'Sale cancelled', 'audience' => 'Seller',
                'description' => 'The order could not be fulfilled and the buyer was refunded.',
                'build' => fn (SampleData $d) => [new SaleCancelled($d->sale, 'The stock ran out before the seller could ship.'), $d->seller],
            ],
            'payout_sent' => [
                'source' => PayoutSent::class,
                'group' => 'Sales (to the seller)', 'label' => 'Payout sent', 'audience' => 'Seller',
                'description' => 'The seller\'s share was transferred to their Stripe account.',
                'build' => fn (SampleData $d) => [new PayoutSent($d->sale), $d->seller],
            ],

            // --- Problem reports
            'issue_opened' => [
                'source' => IssueOpened::class,
                'group' => 'Problem reports', 'label' => 'Problem reported', 'audience' => 'Seller',
                'description' => 'A buyer reported a problem; the payout is on hold.',
                'build' => function (SampleData $d) {
                    $d->issue(IssueStatus::OPEN);

                    return [new IssueOpened($d->issue), $d->seller];
                },
            ],
            'issue_message' => [
                'source' => IssueMessagePosted::class,
                'group' => 'Problem reports', 'label' => 'New message in the thread', 'audience' => 'Buyer',
                'description' => 'The seller answered on the problem report.',
                'build' => function (SampleData $d) {
                    $d->issue(IssueStatus::OPEN);

                    return [new IssueMessagePosted($d->issue, $d->message), $d->buyer];
                },
            ],
            'issue_offer_made' => [
                'source' => IssueOfferMade::class,
                'group' => 'Problem reports', 'label' => 'Offer made', 'audience' => 'Buyer',
                'description' => 'The seller offers a partial refund.',
                'build' => function (SampleData $d) {
                    $d->issue(IssueStatus::OFFER_PENDING, ['offer_type' => IssueResolution::PARTIAL_REFUND, 'offer_cents' => 2000, 'offered_at' => now()]);

                    return [new IssueOfferMade($d->issue), $d->buyer];
                },
            ],
            'issue_offer_accepted' => [
                'source' => IssueOfferAnswered::class,
                'group' => 'Problem reports', 'label' => 'Offer accepted', 'audience' => 'Seller',
                'description' => 'The buyer accepted the offer; the refund was paid and the problem is closed.',
                'build' => function (SampleData $d) {
                    $d->issue(IssueStatus::RESOLVED, [
                        'offer_type' => IssueResolution::PARTIAL_REFUND, 'offer_cents' => 2000, 'offered_at' => now()->subDay(),
                        'resolution' => IssueResolution::PARTIAL_REFUND, 'refunded_cents' => 2000, 'resolved_at' => now(), 'resolved_by' => $d->buyer->getKey(),
                    ]);

                    return [new IssueOfferAnswered($d->issue, true), $d->seller];
                },
            ],
            'issue_offer_declined' => [
                'source' => IssueOfferAnswered::class,
                'group' => 'Problem reports', 'label' => 'Offer declined', 'audience' => 'Seller',
                'description' => 'The buyer declined; the problem is open again.',
                'build' => function (SampleData $d) {
                    $d->issue(IssueStatus::OPEN);

                    return [new IssueOfferAnswered($d->issue, false), $d->seller];
                },
            ],
            'issue_escalated_admin' => [
                'source' => IssueEscalated::class,
                'group' => 'Problem reports', 'label' => 'Escalated: needs a decision', 'audience' => 'Admin',
                'description' => 'Buyer and seller did not agree: the admins decide.',
                'build' => function (SampleData $d) {
                    $d->issue(IssueStatus::ESCALATED, ['escalated_at' => now(), 'escalated_by' => $d->buyer->getKey()]);

                    return [new IssueEscalated($d->issue, $d->buyer), $d->admin];
                },
            ],
            'issue_escalated_party' => [
                'source' => IssueEscalated::class,
                'group' => 'Problem reports', 'label' => 'Escalated: the team was called in', 'audience' => 'Buyer / seller',
                'description' => 'What the buyer or the seller reads when the problem was escalated.',
                'build' => function (SampleData $d) {
                    $d->issue(IssueStatus::ESCALATED, ['escalated_at' => now(), 'escalated_by' => $d->buyer->getKey()]);

                    return [new IssueEscalated($d->issue, $d->buyer), $d->seller];
                },
            ],
            'issue_resolved' => [
                'source' => IssueResolved::class,
                'group' => 'Problem reports', 'label' => 'Problem resolved', 'audience' => 'Buyer',
                'description' => 'The problem was settled with a refund.',
                'build' => function (SampleData $d) {
                    $d->issue(IssueStatus::RESOLVED, ['resolution' => IssueResolution::PARTIAL_REFUND, 'refunded_cents' => 2000, 'resolved_at' => now(), 'resolved_by' => $d->admin->getKey()]);

                    return [new IssueResolved($d->issue), $d->buyer];
                },
            ],
            'dispute_opened' => [
                'source' => DisputeOpened::class,
                'group' => 'Problem reports', 'label' => 'Bank dispute opened', 'audience' => 'Admin',
                'description' => 'A buyer\'s bank opened a chargeback; payouts are on hold.',
                'build' => function (SampleData $d) {
                    $d->issue(IssueStatus::ESCALATED, ['escalated_at' => now(), 'stripe_dispute_id' => 'dp_preview_1']);

                    return [new DisputeOpened($d->saleOrder, [$d->issue], [
                        'id' => 'dp_preview_1', 'amount' => $d->sale->subtotal_cents + $d->sale->shipping_cents, 'reason' => 'product_not_received',
                        'evidence_details' => ['due_by' => now()->addDays(9)->timestamp],
                    ]), $d->admin];
                },
            ],

            // --- Money
            'stripe_differences' => [
                'source' => StripeDifferencesFound::class,
                'group' => 'Money', 'label' => 'Stripe differences found', 'audience' => 'Admin',
                'description' => 'The daily check found differences between the shop\'s records and Stripe\'s ledger.',
                'build' => function (SampleData $d) {
                    $make = fn (DiscrepancyKind $kind, string $key, ?int $expected, ?int $actual, string $details) => StripeDiscrepancy::create([
                        'kind' => $kind, 'key' => $key, 'order_id' => $d->saleOrder->getKey(), 'stripe_id' => $d->saleOrder->stripe_charge_id,
                        'expected_cents' => $expected, 'actual_cents' => $actual, 'currency' => $d->saleOrder->currency, 'details' => $details,
                        'first_seen_at' => now(), 'last_seen_at' => now(),
                    ]);

                    return [new StripeDifferencesFound(new \Illuminate\Database\Eloquent\Collection([
                        $make(DiscrepancyKind::CHARGE_AMOUNT, 'order:preview-1', $d->saleOrder->total_cents, $d->saleOrder->total_cents - 500, 'Order '.$d->saleOrder->number.' is for more here than Stripe charged.'),
                        $make(DiscrepancyKind::TRANSFER_MISSING, 'seller_order:preview-1', $d->sale->payout_cents, null, 'The payout was recorded as a transfer Stripe does not have.'),
                    ])), $d->admin];
                },
            ],

            // --- The store
            'purchase_orders_to_send' => [
                'source' => PurchaseOrdersToSend::class,
                'group' => 'The store', 'label' => 'Purchase orders to send', 'audience' => 'Admin',
                'description' => 'A store order includes items that suppliers ship.',
                'build' => fn (SampleData $d) => [new PurchaseOrdersToSend($d->storeOrder, 1), $d->admin],
            ],
            'purchase_order' => [
                'source' => PurchaseOrderMail::class,
                'group' => 'The store', 'label' => 'Purchase order', 'audience' => 'Supplier',
                'description' => 'Sent to the supplier: businesslike, no marketing links in the footer. Replies go to the store address.',
                'build' => fn (SampleData $d) => [new PurchaseOrderMail($d->purchaseOrder->fresh(['supplier', 'items', 'order'])), $d->supplier],
            ],

            // --- Accounts
            'verify_email' => [
                'source' => WaveVerifyEmail::class,
                'group' => 'Accounts', 'label' => 'Verify your e-mail (Wave)', 'audience' => 'New member',
                'description' => 'Wave\'s own verification mail (code link). The current sign-up flow sends the signed-link mail below instead; this one is themed too, should it be used.',
                'build' => function (SampleData $d) {
                    $d->buyer->forceFill(['verification_code' => Str::lower(Str::random(40))]);

                    return [new WaveVerifyEmail($d->buyer), $d->buyer];
                },
            ],
            'verify_email_link' => [
                'source' => LaravelVerifyEmail::class,
                'group' => 'Accounts', 'label' => 'Verify your e-mail address (signed link)', 'audience' => 'New member',
                'description' => 'The standard Laravel verification mail, when e-mail verification is switched on.',
                'build' => fn (SampleData $d) => [new LaravelVerifyEmail(), $d->buyer],
            ],
            'reset_password' => [
                'source' => ResetPassword::class,
                'group' => 'Accounts', 'label' => 'Reset your password', 'audience' => 'Member',
                'description' => 'Sent from the "forgot your password" page.',
                'build' => fn (SampleData $d) => [new ResetPassword(Str::random(64)), $d->buyer],
            ],
        ];
    }

    /** @return array<string, array{source: class-string, group: string, label: string, audience: string, description: string}> */
    public function catalogue(): array
    {
        return array_map(fn (array $definition) => array_diff_key($definition, ['build' => true]), $this->definitions());
    }

    /**
     * Render every e-mail. One failing e-mail does not stop the others: it comes back with `error` set.
     *
     * @return array<string, array{source: class-string, group: string, label: string, audience: string, description: string, subject: ?string, to: ?string, html: ?string, text: ?string, error: ?string}>
     */
    public function renderAll(User $admin): array
    {
        $previews = [];

        DB::beginTransaction();

        try {
            $data = SampleData::build($admin);

            foreach ($this->definitions() as $key => $definition) {
                $previews[$key] = array_diff_key($definition, ['build' => true]) + $this->render($definition['build'], $data);
            }
        } catch (Throwable $e) {
            report($e);

            foreach ($this->definitions() as $key => $definition) {
                $previews[$key] ??= array_diff_key($definition, ['build' => true]) + ['subject' => null, 'to' => null, 'html' => null, 'text' => null, 'error' => 'The sample data could not be built: '.$e->getMessage()];
            }
        } finally {
            DB::rollBack();
        }

        return $previews;
    }

    /** @return array{subject: ?string, to: ?string, html: ?string, text: ?string, error: ?string} */
    private function render(Closure $build, SampleData $data): array
    {
        try {
            [$mail, $to] = $build($data);

            if ($mail instanceof Mailable) {
                $envelope = $mail->envelope();

                return [
                    'subject' => $envelope->subject,
                    'to' => (string) ($to->order_email ?? $to->email ?? ''),
                    'html' => (string) $mail->render(),
                    'text' => null,
                    'error' => null,
                ];
            }

            /** @var MailMessage $message */
            $message = $mail->toMail($to);

            return [
                'subject' => $message->subject ?: Str::headline(class_basename($mail)),
                'to' => (string) ($to->email ?? ''),
                'html' => (string) $message->render(),
                'text' => $this->text($message),
                'error' => null,
            ];
        } catch (Throwable $e) {
            report($e);

            return ['subject' => null, 'to' => null, 'html' => null, 'text' => null, 'error' => $e->getMessage()];
        }
    }

    /** The plain-text alternative that goes out with the HTML. */
    private function text(MailMessage $message): ?string
    {
        if (isset($message->view) || ! isset($message->markdown)) {
            return null;
        }

        return trim((string) app(Markdown::class)->renderText($message->markdown, $message->data()));
    }
}
