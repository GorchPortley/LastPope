<?php

namespace App\Http\Livewire;

use App\Models\Design;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\Data\CartOutcome;
use Livewire\Component;

class AddToCartButton extends Component
{
    public Design $design;
    public bool $isInCart;
    public bool $isOwned;

    public function mount(Design $design, bool $isInCart)
    {
        $this->design = $design;
        $this->isInCart = $isInCart;
        $this->isOwned = $this->ownsDesign();
    }

    /** The user owns the design, or already has an active entitlement (purchased or added for free). */
    private function ownsDesign(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $this->design->user_id === $user->id
            || $user->entitlementFor($this->design)?->isActive() === true;
    }

    public function addToCart()
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        // All cart rules (own items, already owned, free items, availability) live in CartService.
        $result = app(CartService::class)->add(auth()->user(), $this->design);

        $this->isOwned = $this->ownsDesign();
        $this->isInCart = $this->isInCart || in_array($result->outcome, [CartOutcome::ADDED, CartOutcome::ALREADY_IN_CART], true);

        session()->flash('message', $result->message());

        if ($result->ok()) {
            $this->dispatch('cart-updated');
        }
    }
}
