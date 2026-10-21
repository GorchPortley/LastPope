<?php

namespace App\Http\Livewire;

use App\Enums\OrderStatus;
use App\Models\Order;
use Livewire\Component;

/**
 * The "thank you" part of /checkout/success. It only displays the order: the Stripe webhook does the
 * fulfilment, usually within a second or two, so a still-pending order is polled every few seconds
 * until it is paid (or cancelled), for at most MAX_POLLS rounds.
 */
class CheckoutStatus extends Component
{
    /** 20 rounds of 3 seconds: one minute, then the buyer is pointed to the orders page and the e-mail. */
    public const MAX_POLLS = 20;

    public Order $order;

    public int $polls = 0;

    public function mount(Order $order): void
    {
        $this->order = $order;
        $this->authorizeBuyer();
    }

    public function poll(): void
    {
        $this->authorizeBuyer();

        $this->polls++;
        $this->order->refresh();
    }

    public function render()
    {
        $this->authorizeBuyer();

        $order = $this->order->load(['items', 'sellerOrders.seller.sellerProfile']);
        $pending = $order->status === OrderStatus::PENDING;

        return view('livewire.checkout-status', [
            'order' => $order,
            'pending' => $pending,
            'polling' => $pending && $this->polls < self::MAX_POLLS,
            'hasDigital' => $order->items->contains(fn ($item) => $item->is_digital),
            'hasPhysical' => $order->items->contains(fn ($item) => ! $item->is_digital),
        ]);
    }

    private function authorizeBuyer(): void
    {
        abort_unless(auth()->check() && (int) $this->order->user_id === (int) auth()->id(), 404);
    }
}
