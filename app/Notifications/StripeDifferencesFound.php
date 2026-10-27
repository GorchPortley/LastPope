<?php

namespace App\Notifications;

use App\Models\StripeDiscrepancy;
use App\Notifications\Concerns\DescribesOrders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * To the admins: the daily Stripe reconciliation found differences between the site's records and Stripe's ledger that
 * nobody was told about yet (docs/PLAN.md 11, R6.3). Lists them (the first few; the rest are on the Reconciliation page).
 * Mail + database, queued.
 */
class StripeDifferencesFound extends Notification implements ShouldQueue
{
    use DescribesOrders;
    use Queueable;

    /** How many differences the mail spells out. */
    private const LISTED = 15;

    /** @param  Collection<int, StripeDiscrepancy>  $discrepancies */
    public function __construct(public Collection $discrepancies)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->discrepancies->loadMissing('order');
        $count = $this->discrepancies->count();
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting($this->title())
            ->line(($count === 1 ? 'The daily check found a difference' : "The daily check found {$count} differences").' between the shop\'s records and Stripe\'s ledger.')
            ->line(new HtmlString($this->table()));

        if ($count > self::LISTED) {
            $mail->line('And '.($count - self::LISTED).' more on the Reconciliation page.');
        }

        return $mail
            ->line('Differences close by themselves when a later check finds them fixed. Close one yourself, with a note, when you have sorted it out in Stripe.')
            ->action('Open the reconciliation', $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $count = $this->discrepancies->count();

        return $this->payload(
            'stripe_differences',
            $this->title(),
            $this->discrepancies->take(3)->map(fn (StripeDiscrepancy $d) => $d->kind->label())->implode(', ').($count > 3 ? ' and '.($count - 3).' more.' : '.'),
            $this->url(),
            ['discrepancy_ids' => $this->discrepancies->modelKeys()],
        );
    }

    /** "Stripe reconciliation: 3 new differences" */
    public function title(): string
    {
        $count = $this->discrepancies->count();

        return 'Stripe reconciliation: '.$count.' new '.($count === 1 ? 'difference' : 'differences');
    }

    /** The first differences as one Markdown table (the mail template renders it as an HTML table). */
    private function table(): string
    {
        $cell = fn (string $text): string => str_replace(['|', "\n"], ['\\|', ' '], $text);
        $amount = fn (?int $cents, StripeDiscrepancy $d): string => $cents === null ? '-' : money($cents, $d->currency);

        $rows = $this->discrepancies->take(self::LISTED)->map(fn (StripeDiscrepancy $d) => '| '.$cell($d->kind->label()).' | '
            .$cell($d->order?->number ?? $d->stripe_id ?? '-').' | '.$amount($d->expected_cents, $d).' | '.$amount($d->actual_cents, $d).' |');

        return "| Difference | About | Shop | Stripe |\n|:-----|:-----|------:|------:|\n".$rows->implode("\n");
    }

    /** The reconciliation page in the admin panel (falls back to the panel's home while that page does not exist). */
    private function url(): string
    {
        $resource = \App\Filament\Resources\StripeDiscrepancyResource::class;

        return class_exists($resource) ? $resource::getUrl('index') : url('/admin');
    }
}
