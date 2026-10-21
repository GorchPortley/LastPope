<?php

namespace App\Http\Controllers\Stripe;

use App\Http\Controllers\Controller;
use App\Models\StripeEvent;
use App\Services\Payments\Exceptions\InvalidWebhookException;
use App\Services\Payments\PaymentGateway;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Shared flow of the two Stripe webhook endpoints: verify the signature with the endpoint's own
 * secret, de-duplicate by event id (stripe_events), hand the event to the handler, answer 200.
 *
 * Bad signature -> 400. Missing secret -> 503 (Stripe retries). A handler that throws -> 500 and the
 * event is not marked processed, so Stripe redelivers it. These routes are outside the web
 * middleware group (no session, no CSRF).
 */
abstract class StripeWebhookController extends Controller
{
    /** The signing secret of this endpoint. */
    abstract protected function secret(): ?string;

    /** @return string 'handled' or 'ignored' */
    abstract protected function process(object $event): string;

    public function __invoke(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $secret = $this->secret();

        if (blank($secret)) {
            Log::error('Stripe webhook received but its signing secret is not configured.', ['path' => $request->path()]);

            return response()->json(['error' => 'Webhook secret not configured.'], 503);
        }

        try {
            $event = $gateway->constructWebhookEvent($request->getContent(), (string) $request->header('Stripe-Signature', ''), $secret);
        } catch (InvalidWebhookException $e) {
            Log::warning('Stripe webhook rejected: '.$e->getMessage(), ['path' => $request->path()]);

            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $record = $this->claim($event);

        if ($record->isProcessed()) {
            return response()->json(['status' => 'duplicate']);
        }

        $result = $this->process($event);
        $record->markProcessed();

        return response()->json(['status' => $result]);
    }

    private function claim(object $event): StripeEvent
    {
        try {
            return StripeEvent::firstOrCreate(['id' => $event->id], ['type' => $event->type]);
        } catch (UniqueConstraintViolationException) {
            // The same event arrived twice at the same moment.
            return StripeEvent::findOrFail($event->id);
        }
    }
}
