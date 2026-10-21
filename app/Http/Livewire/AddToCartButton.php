<?php

namespace App\Http\Livewire;

use App\Enums\EntitlementSource;
use App\Models\Design;
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
        $user = auth()->user();

        if (! $this->design->isAvailableForPurchase()) {
            session()->flash('message', 'This design is not available right now.');
            return;
        }

        if ($this->design->price_cents <= 0) {
            if ($this->isOwned) {
                session()->flash('message', 'You already own this design!');
                return;
            }

            $user->grantEntitlement($this->design, EntitlementSource::FREE);

            $this->isOwned = true;
            session()->flash('message', 'Free design added to your library!');
            return;
        }

        if ($this->isInCart || $this->isOwned) {
            session()->flash('message', $this->isOwned ? 'You already own this design!' : 'Design already in cart!');
            return;
        }

        $cart = $user->cart()->firstOrCreate();
        $cart->items()->firstOrCreate(
            ['purchasable_type' => $this->design->getMorphClass(), 'purchasable_id' => $this->design->id],
            ['quantity' => 1, 'unit_price_cents' => $this->design->price_cents],
        );

        $this->isInCart = true;
        session()->flash('message', 'Added to cart!');
        $this->dispatch('cart-updated');
    }
}?>
