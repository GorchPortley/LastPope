<?php

namespace App\Http\Livewire;

use App\Services\Marketplace\CartService;
use Livewire\Attributes\On;
use Livewire\Component;

/** The cart link with the live number of units in the header. Refreshes on the `cart-updated` event. */
class CartIcon extends Component
{
    public int $count = 0;

    public function mount(): void
    {
        $this->refreshCount();
    }

    #[On('cart-updated')]
    public function refreshCount(): void
    {
        $user = auth()->user();

        $this->count = $user ? app(CartService::class)->count($user) : 0;
    }

    public function render()
    {
        return view('livewire.cart-icon');
    }
}
