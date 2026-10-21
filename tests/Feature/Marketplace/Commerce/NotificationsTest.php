<?php

use App\Models\User;
use App\Notifications\NewSale;
use App\Notifications\OrderReceipt;
use App\Notifications\OrderRefunded;
use App\Notifications\OrderShipped;
use App\Notifications\PayoutSent;
use App\Services\Marketplace\RefundService;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Contracts\Queue\ShouldQueue;

beforeEach(function () {
    config(['marketplace.physical_payout_trigger' => 'shipped']);
    $this->buyer = User::factory()->create(['email' => 'buyer@example.test']);
    $this->seller = onboardedSeller();
});

it('renders and describes all five notifications', function () {
    $listing = sellableListing($this->seller, 8000, ['shipping_domestic_cents' => 900]);
    $order = pendingOrder($this->buyer, [$listing, paidDesign(onboardedSeller(), 1200)], addressFor($this->buyer));
    payOrder($order);
    $sellerOrder = $order->sellerOrders()->where('seller_id', $this->seller->id)->first();
    app(ShipmentService::class)->markShipped($this->seller, $sellerOrder, 'UPS', '1Z999AA1', 'https://ups.example/1Z999AA1');
    $sellerOrder->refresh();

    $notifications = [
        [new OrderReceipt($order->fresh()), $this->buyer],
        [new NewSale($sellerOrder), $this->seller],
        [new OrderShipped($sellerOrder), $this->buyer],
        [new PayoutSent($sellerOrder), $this->seller],
        [new OrderRefunded($order->fresh(), 8900, $sellerOrder), $this->buyer],
    ];

    foreach ($notifications as [$notification, $user]) {
        expect($notification)->toBeInstanceOf(ShouldQueue::class)
            ->and($notification->via($user))->toBe(['mail', 'database']);

        $html = (string) $notification->toMail($user)->render();
        expect($html)->toContain($order->number);

        // same keys as the other notifications on the /notifications page
        expect($notification->toArray($user))->toHaveKeys(['body', 'link', 'icon', 'user'])
            ->and($notification->toArray($user)['body'])->toContain($order->number);
    }

    $shipped = (string) (new OrderShipped($sellerOrder))->toMail($this->buyer)->render();
    expect($shipped)->toContain('UPS')->toContain('1Z999AA1')->toContain('https://ups.example/1Z999AA1');
});

it('really sends mail and stores database notifications through the queue', function () {
    $order = pendingOrder($this->buyer, [paidDesign($this->seller, 2500)]);

    payOrder($order);

    $messages = app('mailer')->getSymfonyTransport()->messages();
    $subjects = collect($messages)->map(fn ($m) => $m->getOriginalMessage()->getSubject())->all();
    $recipients = collect($messages)->flatMap(fn ($m) => collect($m->getOriginalMessage()->getTo())->map->getAddress())->all();

    expect($subjects)->toContain('Your order '.$order->number)
        ->and($subjects)->toContain('New sale: order '.$order->number)
        ->and($subjects)->toContain('Payout sent for order '.$order->number)
        ->and($recipients)->toContain('buyer@example.test')
        ->and($recipients)->toContain($this->seller->email);

    $stored = $this->buyer->notifications()->first();
    expect($stored->data['body'])->toContain($order->number)
        ->and($stored->data)->toHaveKeys(['link', 'icon', 'user', 'order_number'])
        ->and($this->seller->notifications()->count())->toBe(2); // new sale + payout
});

it('tells the buyer about refunds', function () {
    $order = pendingOrder($this->buyer, [paidDesign($this->seller, 2500)]);
    payOrder($order);

    app(RefundService::class)->refundOrder(User::factory()->admin()->create(), $order->fresh());

    $refund = $this->buyer->notifications()->where('type', OrderRefunded::class)->first();
    expect($refund)->not->toBeNull()
        ->and($refund->data['amount_cents'])->toBe(2500)
        ->and($refund->data['body'])->toContain('$25.00');
});
