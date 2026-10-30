<?php

namespace App\Notifications\Concerns;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\RecipientLink;
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

    /**
     * The items as one GitHub-flavoured Markdown table (the mail template renders it as an HTML table).
     *
     * @param  Collection<int, OrderItem>  $items
     */
    protected function itemsTable(Collection $items, Order $order): string
    {
        $cell = fn (string $text): string => str_replace(['|', "\n"], ['\\|', ' '], $text);

        $rows = $items->map(fn (OrderItem $item) => '| '.$cell($item->title).' | '.$item->quantity.' | '.$this->amount($item->line_total_cents, $order).' |');

        return "| Item | Qty | Price |\n|:-----|:---:|------:|\n".$rows->implode("\n");
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
            'icon' => \App\Support\Avatars::defaultUrl(),
            'user' => ['name' => config('app.name')],
        ];
    }

    /**
     * The database payload the /notifications page renders: a title, a body and the url to open. `link` is the same
     * url under the older key. Extra keys (ids, amounts) go in $extra.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function payload(string $kind, string $title, string $body, string $url, array $extra = []): array
    {
        return $this->sender() + [
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'link' => $url,
            'kind' => $kind,
        ] + $extra;
    }

    /** The buyer's page for an order, marked for the account it is sent to (RecipientLink). */
    protected function buyerOrderUrl(Order $order, object $notifiable): string
    {
        return RecipientLink::to(route('dashboard.orders.show', ['order' => $order->number]), $notifiable);
    }
}
