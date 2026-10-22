<?php

namespace Database\Factories;

use App\Enums\IssueReason;
use App\Enums\IssueResolution;
use App\Enums\IssueStatus;
use App\Models\OrderIssue;
use App\Models\SellerOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Problem reports. Always build them for an existing seller order: OrderIssue::factory()->forSellerOrder($so)->create().
 * Rows are created directly (no IssueService, no notifications, no refunds): the states set the columns that go with
 * them, the caller keeps the seller order consistent (a refunded one, a held payout...).
 *
 * @extends Factory<OrderIssue>
 */
class OrderIssueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reason' => IssueReason::NOT_AS_DESCRIBED,
            'status' => IssueStatus::OPEN,
            'refunded_cents' => 0,
            'recovered_cents' => 0,
        ];
    }

    /** The order, buyer and seller come from the seller order. */
    public function forSellerOrder(SellerOrder $sellerOrder): static
    {
        $sellerOrder->loadMissing('order');

        return $this->state(fn () => [
            'order_id' => $sellerOrder->order_id,
            'seller_order_id' => $sellerOrder->getKey(),
            'buyer_id' => $sellerOrder->order->user_id,
            'seller_id' => $sellerOrder->seller_id,
        ]);
    }

    public function reason(IssueReason $reason): static
    {
        return $this->state(fn () => ['reason' => $reason]);
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => IssueStatus::OPEN]);
    }

    public function offerPending(IssueResolution $type = IssueResolution::PARTIAL_REFUND, ?int $cents = 2000): static
    {
        return $this->state(fn () => [
            'status' => IssueStatus::OFFER_PENDING,
            'offer_type' => $type,
            'offer_cents' => $type === IssueResolution::PARTIAL_REFUND ? $cents : null,
            'offered_at' => now(),
        ]);
    }

    public function awaitingReturn(): static
    {
        return $this->state(fn () => [
            'status' => IssueStatus::AWAITING_RETURN,
            'offer_type' => IssueResolution::RETURN_REFUND,
            'offered_at' => now()->subDay(),
        ]);
    }

    public function escalated(): static
    {
        return $this->state(fn () => ['status' => IssueStatus::ESCALATED, 'escalated_at' => now()]);
    }

    public function resolved(IssueResolution $resolution = IssueResolution::NO_ACTION, int $refundedCents = 0): static
    {
        return $this->state(fn () => [
            'status' => IssueStatus::RESOLVED,
            'resolution' => $resolution,
            'refunded_cents' => $refundedCents,
            'resolved_at' => now(),
        ]);
    }

    public function dispute(string $id = 'dp_test_1'): static
    {
        return $this->state(fn () => ['stripe_dispute_id' => $id, 'dispute_status' => 'needs_response', 'dispute_reason' => 'fraudulent']);
    }
}
