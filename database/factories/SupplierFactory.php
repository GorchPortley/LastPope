<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Active suppliers that ship from the US in 3-7 business days, blind dropship on, purchase orders sent by hand,
 * no stock feed. States: withFeed($url?), withFeedFile($path), manual() (a web portal, no order e-mail), autoSend(),
 * inactive(), international($cents), freeShippingOver($cents), withFee($cents), withLeadTime($min, $max).
 *
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->company().' Distribution';

        return [
            'name' => $name,
            'contact_name' => $this->faker->name(),
            'contact_email' => $this->faker->unique()->safeEmail(),
            'contact_phone' => $this->faker->phoneNumber(),
            'order_email' => 'orders@'.Str::slug($name).'.example.test',
            'website' => 'https://'.Str::slug($name).'.example.test',
            'account_number' => strtoupper($this->faker->bothify('AC-#####')),
            'ships_from_country' => 'US',
            'lead_time_min_days' => 3,
            'lead_time_max_days' => 7,
            'dropship_fee_cents' => 0,
            'blind_dropship' => true,
            'auto_send_purchase_orders' => false,
            'shipping_domestic_cents' => 899,
            'shipping_international_cents' => 2499,
            'additional_item_shipping_cents' => 300,
            'free_shipping_over_cents' => null,
            'notes' => null,
            'active' => true,
            'feed_url' => null,
            'feed_file_path' => null,
            'feed_sku_column' => 'sku',
            'feed_quantity_column' => 'quantity',
            'feed_cost_column' => null,
        ];
    }

    /** A stock feed over HTTP (sku, quantity and cost columns). */
    public function withFeed(?string $url = null): static
    {
        return $this->state(fn () => [
            'feed_url' => $url ?? 'https://feeds.example.test/stock-'.Str::lower(Str::random(6)).'.csv',
            'feed_cost_column' => 'cost',
        ]);
    }

    /** A feed file an admin uploaded (a path on the private disk). */
    public function withFeedFile(string $path = 'suppliers/feed.csv'): static
    {
        return $this->state(fn () => ['feed_file_path' => $path, 'feed_cost_column' => 'cost']);
    }

    /** Orders go into the supplier's web portal: no order e-mail address. */
    public function manual(): static
    {
        return $this->state(fn () => ['order_email' => null, 'auto_send_purchase_orders' => false]);
    }

    /** Purchase orders are e-mailed as soon as an order is paid. */
    public function autoSend(): static
    {
        return $this->state(fn () => ['auto_send_purchase_orders' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    public function withLeadTime(int $min, int $max): static
    {
        return $this->state(fn () => ['lead_time_min_days' => $min, 'lead_time_max_days' => $max]);
    }

    /** What the supplier charges us per purchase order. */
    public function withFee(int $cents): static
    {
        return $this->state(fn () => ['dropship_fee_cents' => $cents]);
    }

    /** null = domestic only. */
    public function international(?int $cents = 2499): static
    {
        return $this->state(fn () => ['shipping_international_cents' => $cents]);
    }

    public function freeShippingOver(int $cents): static
    {
        return $this->state(fn () => ['free_shipping_over_cents' => $cents]);
    }

    /** Domestic price, international price (null = none) and the price per further unit, as the buyer pays. */
    public function shipping(int $domestic, ?int $international = null, int $additional = 0): static
    {
        return $this->state(fn () => [
            'shipping_domestic_cents' => $domestic,
            'shipping_international_cents' => $international,
            'additional_item_shipping_cents' => $additional,
        ]);
    }
}
