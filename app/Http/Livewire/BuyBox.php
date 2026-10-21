<?php

namespace App\Http\Livewire;

use App\Contracts\Purchasable;
use App\Enums\ListingStatus;
use App\Models\CartItem;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\User;
use App\Services\Marketplace\CartService;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * The buy box of every product page: a design, a driver's data pack or a physical listing.
 *
 *     <livewire:buy-box :purchasable="$design" />
 *     <livewire:buy-box :purchasable="$driver" label="Data pack" />
 *
 * It only reads (User::hasAccessTo(..., false) never grants anything) until the buyer presses the button;
 * every cart rule then lives in CartService. A free digital item goes straight to the library.
 *
 * States (see state()): login, owner, owned, revoked, unavailable, free, in_cart, add.
 */
class BuyBox extends Component
{
    public Model $purchasable;

    public ?string $label = null;

    /** Units to put in the cart (listings only; digital items are always 1). */
    public int $quantity = 1;

    public ?string $notice = null;

    public string $noticeLevel = 'info';

    public function mount(Model $purchasable, ?string $label = null): void
    {
        $this->purchasable = $purchasable;
        $this->label = $label;
    }

    public function add()
    {
        $user = auth()->user();

        if (! $user) {
            return redirect()->route('login');
        }

        /** @var Purchasable&Model $purchasable */
        $purchasable = $this->purchasable;
        $quantity = $purchasable->isDigital() ? 1 : max(1, $this->quantity);

        $result = app(CartService::class)->add($user, $purchasable, $quantity);

        $this->notice = $result->message();
        $this->noticeLevel = $result->level();

        if ($result->ok()) {
            $this->dispatch('cart-updated');
        }
    }

    /**
     * What the box shows for the current visitor.
     *
     * @return array{status: string, price: int, free: bool, digital: bool, reason: ?string, max: int, inCart: int, downloadUrl: ?string, lot: ?string, kind: string}
     */
    public function state(): array
    {
        /** @var Purchasable&Model $p */
        $p = $this->purchasable;
        $user = auth()->user();
        $digital = $p->isDigital();
        $price = $p->purchasablePriceCents();
        $free = $digital && $price <= 0;

        $state = [
            'status' => 'add',
            'price' => $price,
            'free' => $free,
            'digital' => $digital,
            'reason' => null,
            'max' => 1,
            'inCart' => 0,
            'downloadUrl' => null,
            'lot' => $p instanceof Listing && $p->isLot() ? ($p->units_per_lot === 2 ? 'pair' : 'set') : null,
            'kind' => $p->getMorphClass(),
        ];

        $seller = $p->getSeller();
        $isOwner = $user !== null && $seller !== null && $seller->is($user);

        if ($digital) {
            $state['downloadUrl'] = $this->downloadUrl($p);
        }

        // 1. The seller's own item.
        if ($isOwner) {
            $state['status'] = 'owner';

            return $state;
        }

        // 2. Digital items the visitor already has: an entitlement, or admin access.
        if ($digital && $user !== null) {
            $entitlement = $user->entitlementFor($p);

            if ($entitlement !== null && ! $entitlement->isActive()) {
                $state['status'] = 'revoked';

                return $state;
            }

            if ($user->hasAccessTo($p, false) && ($entitlement !== null || $user->isPlatformSeller())) {
                $state['status'] = 'owned';

                return $state;
            }
        }

        // 3. Can it be bought at all right now?
        $available = $p instanceof Listing ? $p->isAvailableForPurchase($user) : $p->isAvailableForPurchase();

        if ($p instanceof Listing) {
            $state['max'] = max(1, $p->availableQuantity($user));
        }

        if (! $available) {
            $state['status'] = 'unavailable';
            $state['reason'] = $this->unavailableReason($p, $user);

            return $state;
        }

        if ($user === null) {
            $state['status'] = 'login';

            return $state;
        }

        if ($free) {
            $state['status'] = 'free';

            return $state;
        }

        $inCart = CartItem::query()
            ->whereHas('cart', fn ($q) => $q->where('user_id', $user->getKey()))
            ->where('purchasable_type', $p->getMorphClass())
            ->where('purchasable_id', $p->getKey())
            ->first();
        $state['inCart'] = (int) ($inCart?->quantity ?? 0);
        $state['status'] = $digital && $state['inCart'] > 0 ? 'in_cart' : 'add';

        return $state;
    }

    public function render()
    {
        return view('livewire.buy-box', ['state' => $this->state()]);
    }

    /** The newest snapshot, if there is one: the file the "Download" button gives. */
    private function downloadUrl(Model $p): ?string
    {
        if (! $p instanceof Design && ! $p instanceof Driver) {
            return null;
        }

        $snapshot = $p->snapshots()->orderByDesc('id')->first();

        if ($snapshot === null) {
            return null;
        }

        return $p instanceof Design
            ? route('design-snapshots.download', $snapshot)
            : route('driver-snapshots.download', $snapshot);
    }

    private function unavailableReason(Model $p, ?User $user): string
    {
        if ($p instanceof Listing) {
            if (in_array($p->status, [ListingStatus::SOLD_OUT], true) || ($p->status === ListingStatus::ACTIVE && $p->quantity < 1)) {
                return 'Sold out.';
            }

            if ($p->status !== ListingStatus::ACTIVE) {
                return 'This listing is not for sale right now.';
            }

            if (! $p->sellerCanBePaid()) {
                return 'The seller cannot receive payments yet.';
            }

            return 'Every unit is on hold in another buyer\'s checkout. Try again in a few minutes.';
        }

        if (! $p->active) {
            return 'This item is not published.';
        }

        if (! $p->hasSnapshots()) {
            return 'No files have been published for download yet.';
        }

        if (! $p->sellerCanBePaid()) {
            return 'The seller cannot receive payments yet.';
        }

        return 'This item is not available right now.';
    }
}
