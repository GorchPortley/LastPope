<?php

namespace App\Jobs;

use App\Models\AnalyticsEvent;
use App\Support\Analytics\Ga4;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends one server-side event to GA4 through the Measurement Protocol (docs/PLAN.md 12). Built by Ga4Reporter.
 *
 * Idempotent: `$key` (one per order / refund) is recorded in analytics_events once GA4 accepted the event, so a
 * redelivered webhook or a retried job never reports twice. A failure is retried with a back-off and then only
 * logged: analytics must never matter to a payment.
 */
class SendGa4Event implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $body  the Measurement Protocol body (client_id, user_id, events)
     */
    public function __construct(
        public readonly string $key,
        public readonly array $body,
    ) {
        $this->afterCommit = true;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(): void
    {
        if (! Ga4::canSendFromServer()) {
            return;
        }

        $record = AnalyticsEvent::query()->firstOrCreate(['key' => $this->key]);

        if ($record->sent_at !== null) {
            return;
        }

        $response = Http::timeout(5)
            ->asJson()
            ->post(config('ga4.collect_url').'?'.http_build_query([
                'measurement_id' => config('ga4.measurement_id'),
                'api_secret' => config('ga4.api_secret'),
            ]), $this->body);

        if ($response->failed()) {
            throw new RuntimeException("GA4 Measurement Protocol answered {$response->status()} for {$this->key}.");
        }

        $record->forceFill(['sent_at' => now()])->save();
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('A GA4 server event could not be sent.', ['key' => $this->key, 'error' => $e->getMessage()]);
    }
}
