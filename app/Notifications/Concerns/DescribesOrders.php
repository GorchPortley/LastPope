<?php

namespace App\Notifications\Concerns;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;

/** Small formatting helpers shared by the marketplace notifications. */
trait DescribesOrders
{
    protected function amount(int $cents, Order $order): string
    {
        return money($cents, $order->currency);
    }

    /** "2 x Peerless 830869 - $48.00" */
    protected function itemLine(OrderItem $item, Order $order): string
    {
        $quantity = $item->quantity > 1 ? $item->quantity.' x ' : '';

        return $quantity.$item->title.' - '.$this->amount($item->line_total_cents, $order);
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @return array<int, string>
     */
    protected function itemLines(Collection $items, Order $order): array
    {
        return $items->map(fn (OrderItem $item) => $this->itemLine($item, $order))->all();
    }

    /** The snapshot address as lines of text. */
    protected function addressLines(?array $address): array
    {
        if (blank($address)) {
            return [];
        }

        $cityLine = trim(implode(' ', array_filter([$address['postal_code'] ?? null, $address['city'] ?? null, $address['state'] ?? null])));

        return array_values(array_filter([
            $address['name'] ?? null,
            $address['line1'] ?? null,
            $address['line2'] ?? null,
            $cityLine,
            strtoupper((string) ($address['country'] ?? '')),
        ], fn ($line) => filled($line)));
    }

    /** @return array{icon: string, user: array{name: string}} the keys the /notifications page expects */
    protected function sender(): array
    {
        return [
            'icon' => '/storage/demo/default.png',
            'user' => ['name' => config('app.name')],
        ];
    }
}
