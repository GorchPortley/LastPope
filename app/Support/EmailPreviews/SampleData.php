<?php

namespace App\Support\EmailPreviews;

use App\Enums\FulfillmentMode;
use App\Enums\IssueReason;
use App\Enums\IssueStatus;
use App\Enums\ListingCategory;
use App\Enums\ListingChannel;
use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Models\Address;
use App\Models\Design;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderIssue;
use App\Models\OrderIssueMessage;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Services\Marketplace\ShipmentService;
use Database\Factories\Support\OrderBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * The made-up shop that the e-mail previews are rendered from: a buyer, a seller, a supplier, a marketplace sale that
 * shipped, a store order that ships in two parcels (own stock + a supplier) with its purchase order, a digital order and
 * one problem report with a thread. Built with the real order and shipment code, so a preview shows what the app really
 * sends, but only ever inside a database transaction that the caller rolls back (EmailPreviews): nothing is kept.
 *
 * While it is built nothing leaves the app: notifications, mails and queued jobs are faked (the purchase order service
 * would otherwise tell the real admins). It uses plain Eloquent, no factories, so it works on a server installed
 * without the dev dependencies.
 */
final class SampleData
{
    public User $buyer;

    public User $seller;

    public Supplier $supplier;

    /** A marketplace order of a kit from $seller, shipped. */
    public Order $saleOrder;

    public SellerOrder $sale;

    /** A paid store order (admin = platform seller): an own-stock driver and a dropshipped one, so two parcels. */
    public Order $storeOrder;

    public SellerOrder $storeSale;

    /** The own-stock parcel of the store order, shipped; the supplier's one is still to come. */
    public Shipment $firstParcel;

    public SupplierOrder $purchaseOrder;

    /** A paid order of a design (digital only). */
    public Order $digitalOrder;

    /** A problem report on $sale; the previews set its state before they use it (see issue()). */
    public OrderIssue $issue;

    public OrderIssueMessage $message;

    /** @param User $admin the admin looking at the previews: the platform seller of the store order, the admin recipient */
    public function __construct(public readonly User $admin)
    {
    }

    public static function build(User $admin): self
    {
        $data = new self($admin);

        // Nothing may be sent or queued while the sample shop is made.
        $originals = [
            Notification::class => Notification::getFacadeRoot(),
            Mail::class => Mail::getFacadeRoot(),
            Queue::class => Queue::getFacadeRoot(),
            Bus::class => Bus::getFacadeRoot(),
        ];
        Notification::fake();
        Mail::fake();
        Queue::fake();
        Bus::fake();

        try {
            Model::unguarded(fn () => $data->make());
        } finally {
            foreach ($originals as $facade => $original) {
                $facade::swap($original);
            }
        }

        return $data;
    }

    /**
     * (Re)set the state of the sample problem report and return it: the thread is kept, the status, offer and
     * resolution are replaced by $state.
     *
     * @param  array<string, mixed>  $state
     */
    public function issue(IssueStatus $status, array $state = []): OrderIssue
    {
        $this->issue->forceFill([
            'status' => $status,
            'resolution' => null,
            'offer_type' => null,
            'offer_cents' => null,
            'offered_at' => null,
            'refunded_cents' => 0,
            'escalated_at' => null,
            'escalated_by' => null,
            'resolved_at' => null,
            'resolved_by' => null,
            'stripe_dispute_id' => null,
            ...$state,
        ])->save();

        return $this->issue->refresh();
    }

    private function make(): void
    {
        $this->buyer = $this->user('Alex Rivera', 'alex.rivera');
        Address::create([
            'user_id' => $this->buyer->getKey(),
            'name' => 'Alex Rivera',
            'line1' => '586 Maple Street',
            'city' => 'Springfield',
            'state' => 'OH',
            'postal_code' => '45501',
            'country' => 'US',
            'phone' => '555-0100',
            'is_default' => true,
        ]);

        $this->seller = $this->user('Marcus Hale', 'marcus.hale');
        SellerProfile::create([
            'user_id' => $this->seller->getKey(),
            'shop_name' => 'Hale Acoustics',
            'country' => 'US',
            'stripe_account_id' => 'acct_preview'.Str::lower(Str::random(8)),
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
        ]);

        $this->supplier = Supplier::create([
            'name' => 'Northwind Audio Supply',
            'contact_name' => 'Dana Whitfield',
            'contact_email' => 'dana@northwind-audio.example.test',
            'order_email' => 'orders@northwind-audio.example.test',
            'account_number' => 'SDL-20417',
            'lead_time_min_days' => 5,
            'lead_time_max_days' => 8,
            'blind_dropship' => true,
            'shipping_domestic_cents' => 899,
            'shipping_international_cents' => 2499,
            'additional_item_shipping_cents' => 300,
            'active' => true,
        ]);

        $kit = $this->listing($this->seller, 'Two-way crossover kit, 6.5 inch woofer and dome tweeter', 12000, ListingChannel::MARKETPLACE, ListingCategory::KIT);
        $midbass = $this->listing($this->admin, 'Kestrel K65-MB 6.5 inch midbass', 8900, ListingChannel::STORE, ListingCategory::DRIVER, ['quantity' => 10]);
        $coaxial = $this->listing($this->admin, 'Kestrel KX8-C 8 inch coaxial', 14900, ListingChannel::STORE, ListingCategory::DRIVER, [
            'quantity' => 0,
            'fulfillment' => FulfillmentMode::DROPSHIP->value,
            'supplier_id' => $this->supplier->getKey(),
            'supplier_sku' => 'NW-KX8-001',
            'cost_cents' => 10230,
            'supplier_stock' => 25,
            'supplier_stock_synced_at' => now(),
        ]);
        $design = Model::withoutEvents(fn () => Design::create([
            'user_id' => $this->seller->getKey(),
            'storage_key' => (string) Str::uuid(),
            'name' => 'Harbor 2 two-way monitor',
            'tag' => 'A compact two-way',
            'card_image' => [],
            'active' => true,
            'category' => 'Two-Way',
            'price_cents' => 1900,
            'build_cost_cents' => 33458,
            'impedance' => 8,
            'power' => 100,
            'summary' => '<p>A compact two-way monitor.</p>',
            'description' => '<p>Plans, measurements and crossover files.</p>',
            'bill_of_materials' => [],
        ]));

        // A marketplace sale that shipped yesterday.
        $this->saleOrder = $this->order($this->buyer, 'paid');
        OrderBuilder::build($this->saleOrder, [[$kit, 1]], 'shipped');
        $this->sale = $this->saleOrder->sellerOrders()->firstOrFail()->load(['order', 'items']);

        // A store order: own stock + a supplier's item, paid; the own-stock parcel has shipped.
        $this->storeOrder = $this->order($this->buyer, 'paid');
        OrderBuilder::build($this->storeOrder, [[$midbass, 1], [$coaxial, 1]], 'paid');
        $this->storeSale = $this->storeOrder->sellerOrders()->firstOrFail();
        $this->purchaseOrder = SupplierOrder::query()->where('order_id', $this->storeOrder->getKey())->firstOrFail();
        $this->firstParcel = app(ShipmentService::class)->recordShipment(
            $this->storeSale,
            null,
            ['carrier' => 'USPS', 'tracking_number' => '9400111899223100', 'tracking_url' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=9400111899223100', 'shipped_at' => now()->subHours(20)],
            restrictToOwn: true,
            notify: false,
        );
        $this->storeSale = $this->storeSale->fresh(['order', 'items']);

        // A digital order.
        $this->digitalOrder = $this->order($this->buyer, 'paid');
        OrderBuilder::build($this->digitalOrder, [$design], 'paid');

        // One problem report on the marketplace sale, with a short thread.
        $this->issue = OrderIssue::create([
            'order_id' => $this->saleOrder->getKey(),
            'seller_order_id' => $this->sale->getKey(),
            'buyer_id' => $this->buyer->getKey(),
            'seller_id' => $this->seller->getKey(),
            'reason' => IssueReason::NOT_AS_DESCRIBED,
            'status' => IssueStatus::OPEN,
            'refunded_cents' => 0,
            'recovered_cents' => 0,
        ]);
        $this->issue->messages()->create([
            'author_id' => $this->buyer->getKey(),
            'event' => 'opened',
            'body' => 'The listing says "tested, good condition" but the surround is cracked on one side and the dust cap has a dent. I can send photos if that helps.',
        ]);
        $this->message = $this->issue->messages()->create([
            'author_id' => $this->seller->getKey(),
            'body' => 'Sorry about that, it was fine when I packed it. Could you send a close-up of the surround? I want to see if it happened in transit.',
        ]);
    }

    private function user(string $name, string $username): User
    {
        return User::create([
            'name' => $name,
            'email' => $username.'@example.test',
            'username' => $username.'-preview-'.Str::lower(Str::random(5)),
            // an already hashed placeholder: nobody can log in with it, and hashing a real one would only cost time
            'password' => '$2y$12$'.str_repeat('a', 53),
            'verified' => 1,
            'avatar' => 'demo/default.png',
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function listing(User $seller, string $title, int $priceCents, ListingChannel $channel, ListingCategory $category, array $extra = []): Listing
    {
        // without model events: nothing here may reach out to the forum
        return Model::withoutEvents(fn () => Listing::create($extra + [
            'uuid' => (string) Str::uuid(),
            'slug' => Str::slug($title),
            'seller_id' => $seller->getKey(),
            'channel' => $channel,
            'category' => $category,
            'title' => $title,
            'condition' => ListingCondition::NEW,
            'description' => $title.'.',
            'photos' => [],
            'price_cents' => $priceCents,
            'quantity' => 3,
            'units_per_lot' => 1,
            'ships_from_country' => 'US',
            'shipping_domestic_cents' => 899,
            'shipping_international_cents' => 2499,
            'additional_item_shipping_cents' => 300,
            'handling_days' => 2,
            'returns_accepted' => true,
            'return_policy' => '30 days, buyer pays return shipping.',
            'status' => ListingStatus::ACTIVE,
        ]));
    }

    private function order(User $buyer, string $status): Order
    {
        return Order::create([
            'user_id' => $buyer->getKey(),
            'status' => $status,
            'shipping_address' => $buyer->addresses()->first()?->toSnapshot(),
            'stripe_checkout_session_id' => 'cs_test_preview_'.Str::lower(Str::random(12)),
            'stripe_payment_intent_id' => 'pi_preview_'.Str::lower(Str::random(12)),
            'stripe_charge_id' => 'ch_preview_'.Str::lower(Str::random(12)),
            'paid_at' => now()->subDays(2),
        ]);
    }
}
