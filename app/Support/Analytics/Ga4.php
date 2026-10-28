<?php

namespace App\Support\Analytics;

use App\Models\CartItem;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Marketplace\Data\CartLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;

/**
 * Helpers for the GA4 events (docs/PLAN.md 12). Everything here is inert while GA4_MEASUREMENT_ID is empty.
 *
 * Browser events are plain arrays `['name' => ..., 'params' => [...]]`:
 *
 *     $this->dispatch('ga4', ...Ga4::payload('add_to_cart', Ga4::ecommerce([Ga4::item($design)])));   // Livewire action
 *     <x-analytics.events :events="[Ga4::payload('view_item', ...)]" />                                // plain page
 *     Ga4::flash('login', ['method' => 'password']);                                                    // shown by the next full page
 *
 * Items follow the GA4 ecommerce shape: item_id, item_name, item_category (design / driver / kit / used gear /
 * completed speaker), item_brand (the seller, SDLabs for the store), price in currency units and quantity.
 */
final class Ga4
{
    private const FLASH_KEY = 'ga4.events';

    public static function enabled(): bool
    {
        return filled(config('ga4.measurement_id'));
    }

    /** The server-side calls need the API secret too. */
    public static function canSendFromServer(): bool
    {
        return self::enabled() && filled(config('ga4.api_secret'));
    }

    public static function currency(): string
    {
        return strtoupper((string) config('marketplace.currency', 'usd'));
    }

    /** Cents to currency units, as GA4 wants them. */
    public static function amount(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /** The GA4 user_id: an HMAC of the user id with the app key, never the id or the e-mail. */
    public static function userId(?User $user): ?string
    {
        return $user === null ? null : hash_hmac('sha256', (string) $user->getKey(), (string) config('app.key'));
    }

    // --- Items -----------------------------------------------------------------------------------

    /**
     * One GA4 item for a Design, Driver or Listing. $priceCents / $quantity override the model's current price
     * (a cart line or an order item carries the price that was charged).
     *
     * @return array<string, mixed>
     */
    public static function item(?Model $source, ?int $quantity = null, ?int $priceCents = null, ?string $name = null): array
    {
        $item = match (true) {
            $source instanceof Design => [
                'item_id' => 'design-'.$source->getKey(),
                'item_name' => $source->purchasableTitle(),
                'item_category' => 'design',
                'item_brand' => self::sellerName($source->designer),
                'price' => $source->purchasablePriceCents(),
            ],
            $source instanceof Driver => [
                'item_id' => 'driver-'.$source->getKey(),
                'item_name' => $source->fullName(),
                'item_category' => 'driver',
                'item_brand' => (string) $source->brand,
                'price' => (int) $source->msrp_cents,
            ],
            $source instanceof Listing => [
                // A store driver is the same product as its driver page, so the funnel (view_item -> add_to_cart) lines up.
                'item_id' => $source->isStore() && $source->driver_id ? 'driver-'.$source->driver_id : 'listing-'.$source->getKey(),
                'item_name' => $source->purchasableTitle(),
                'item_category' => str_replace('_', ' ', $source->category->value),
                'item_brand' => self::sellerName($source->seller),
                'price' => $source->purchasablePriceCents(),
            ],
            default => [
                'item_id' => 'item-unknown',
                'item_name' => (string) $name,
                'item_category' => 'item',
                'item_brand' => self::storeName(),
                'price' => 0,
            ],
        };

        if ($name !== null && $name !== '') {
            $item['item_name'] = $name;
        }

        if ($priceCents !== null) {
            $item['price'] = $priceCents;
        }

        $item['price'] = self::amount((int) $item['price']);
        $item['quantity'] = max(1, $quantity ?? 1);

        return $item;
    }

    /** @return array<string, mixed> */
    public static function cartLine(CartLine $line): array
    {
        return self::item($line->purchasable instanceof Model ? $line->purchasable : null, $line->quantity, $line->unitPriceCents, $line->title);
    }

    /** @return array<string, mixed> */
    public static function cartItem(CartItem $item, ?int $quantity = null): array
    {
        $purchasable = $item->purchasable;

        return self::item($purchasable instanceof Model ? $purchasable : null, $quantity ?? $item->quantity, (int) $item->unit_price_cents);
    }

    /** @return array<string, mixed> */
    public static function orderItem(OrderItem $item): array
    {
        $purchasable = $item->purchasable;

        return self::item($purchasable instanceof Model ? $purchasable : null, $item->quantity, $item->unit_price_cents, $item->title);
    }

    /**
     * The params of an ecommerce event: currency, value (sum of price x quantity) and items.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function ecommerce(array $items, array $extra = []): array
    {
        $items = array_values($items);
        $value = round(array_sum(array_map(fn (array $i) => $i['price'] * $i['quantity'], $items)), 2);

        return ['currency' => self::currency(), 'value' => $value] + $extra + ['items' => $items];
    }

    // --- Browser events --------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     * @return array{name: string, params: array<string, mixed>}
     */
    public static function payload(string $name, array $params = []): array
    {
        return ['name' => $name, 'params' => $params];
    }

    /** The value of a `data-ga4-click` / `data-ga4-submit` attribute (a click or form submit sends the event). */
    public static function attr(string $name, array $params = []): HtmlString
    {
        return new HtmlString(e(json_encode(self::payload($name, $params), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    }

    /**
     * The `view_item_list` event for a grid of designs, drivers or listings.
     *
     * @param  iterable<int, Model>  $models
     * @return array{name: string, params: array<string, mixed>}
     */
    public static function itemList(string $listName, iterable $models): array
    {
        $items = [];

        foreach ($models as $model) {
            $items[] = ['index' => count($items)] + self::item($model);
        }

        return self::payload('view_item_list', ['item_list_name' => $listName, 'items' => $items]);
    }

    /** The `data-ga4-click` value of a card link: the visitor picked this item from a list. */
    public static function selectItem(string $listName, Model $model): HtmlString
    {
        return self::attr('select_item', ['item_list_name' => $listName, 'items' => [self::item($model)]]);
    }

    /** The `data-ga4-click` value of a download link: a design or driver snapshot (a ZIP; the design PDF is inside it). */
    public static function download(Model $parent, ?string $fileName): HtmlString
    {
        return self::attr('file_download', [
            'file_name' => (string) $fileName,
            'file_extension' => 'zip',
            'content_type' => $parent instanceof Design ? 'design' : 'driver',
            'item_id' => ($parent instanceof Design ? 'design-' : 'driver-').$parent->getKey(),
        ]);
    }

    /** Queue an event for the next full page the visitor sees (login, sign-up, a return from Stripe). */
    public static function flash(string $name, array $params = []): void
    {
        if (! self::enabled() || ! app()->bound('session.store') || ! request()->hasSession()) {
            return;
        }

        request()->session()->push(self::FLASH_KEY, self::payload($name, $params));
    }

    /** @return array<int, array{name: string, params: array<string, mixed>}> */
    public static function pullFlashed(): array
    {
        if (! self::enabled() || ! app()->bound('session.store') || ! request()->hasSession()) {
            return [];
        }

        return (array) request()->session()->pull(self::FLASH_KEY, []);
    }

    // --- Consent and the buyer's GA client id ------------------------------------------------------

    public static function consentGranted(Request $request): bool
    {
        return $request->cookie((string) config('ga4.consent_cookie')) === 'granted';
    }

    /**
     * The GA client id from the `_ga` cookie ("GA1.1.123456789.1700000000" -> "123456789.1700000000"), or null.
     * GA only writes the cookie when analytics storage was granted.
     */
    public static function clientId(Request $request): ?string
    {
        $cookie = $request->cookie('_ga');

        if (is_string($cookie) && preg_match('/^GA\d+\.\d+\.(\d+\.\d+)$/', $cookie, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * What checkout stores on the order so the server-side purchase can join the buyer's session.
     *
     * @return array{ga_client_id: ?string, analytics_consent: bool}
     */
    public static function checkoutAttributes(Request $request): array
    {
        $consent = self::consentGranted($request);

        return [
            'ga_client_id' => $consent ? self::clientId($request) : null,
            'analytics_consent' => $consent,
        ];
    }

    // --- Internals ---------------------------------------------------------------------------------

    private static function storeName(): string
    {
        return (string) config('marketplace.store_name', 'SDLabs');
    }

    private static function sellerName(?User $seller): string
    {
        if ($seller === null || $seller->isPlatformSeller()) {
            return self::storeName();
        }

        return (string) ($seller->sellerProfile?->shop_name ?: $seller->name);
    }
}
