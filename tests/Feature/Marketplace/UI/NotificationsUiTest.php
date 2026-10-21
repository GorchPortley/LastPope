<?php

use App\Models\User;
use App\Notifications\NewSale;
use App\Notifications\OrderReceipt;
use App\Notifications\OrderRefunded;
use App\Notifications\OrderShipped;
use App\Notifications\PayoutSent;
use App\Notifications\SaleCancelled;
use App\Services\Marketplace\ShipmentService;

beforeEach(function () {
    config(['marketplace.physical_payout_trigger' => 'shipped']);
    $this->buyer = User::factory()->create();
    $this->x = paidListingOrder($this->buyer, null, 6000, ['title' => 'Notified Woofer']);
    app(ShipmentService::class)->markShipped($this->x['seller'], $this->x['sellerOrder'], 'UPS', '1Z999AA1', 'https://ups.example/1Z999AA1');
    $this->so = $this->x['sellerOrder']->fresh(['order', 'items']);
    $this->order = $this->x['order']->fresh();
    $this->seller = $this->x['seller'];
});

function marketplaceNotifications(object $test): array
{
    return [
        'order_receipt' => [new OrderReceipt($test->order), $test->buyer, 'Order confirmed'],
        'new_sale' => [new NewSale($test->so), $test->seller, 'New sale'],
        'order_shipped' => [new OrderShipped($test->so), $test->buyer, 'Your order has shipped'],
        'order_refunded' => [new OrderRefunded($test->order, 6900, $test->so), $test->buyer, 'Refund issued'],
        'payout_sent' => [new PayoutSent($test->so), $test->seller, 'Payout sent'],
        'sale_cancelled' => [new SaleCancelled($test->so, 'The stock ran out.'), $test->seller, 'Sale cancelled'],
    ];
}

it('gives all six marketplace notifications a title, a body and a url in the database payload', function () {
    foreach (marketplaceNotifications($this) as $kind => [$notification, $user, $title]) {
        $data = $notification->toArray($user);

        expect($data)->toHaveKeys(['title', 'body', 'url', 'link', 'icon', 'user', 'kind', 'order_number'])
            ->and($data['title'])->toBe($title)
            ->and($data['kind'])->toBe($kind)
            ->and($data['body'])->toContain($this->order->number)
            ->and($data['url'])->toStartWith('http')->and($data['link'])->toBe($data['url']);
    }

    // buyers land on the order, sellers on their sales
    expect((new OrderReceipt($this->order))->toArray($this->buyer)['url'])->toBe(route('dashboard.orders.show', ['order' => $this->order->number]))
        ->and((new NewSale($this->so))->toArray($this->seller)['url'])->toBe(route('selling.orders'));
});

it('renders the notifications on the /notifications page with title, body and a link to open', function () {
    foreach (marketplaceNotifications($this) as [$notification, $user]) {
        $user->notify($notification);
    }

    $buyerPage = $this->actingAs($this->buyer)->get('/notifications')->assertOk();
    $buyerPage->assertSee('Order confirmed')->assertSee('Your order has shipped')->assertSee('Refund issued')
        ->assertSee('Your order '.$this->order->number.' is paid')
        ->assertSee(route('dashboard.orders.show', ['order' => $this->order->number]), false)
        ->assertDontSee('Payout sent');

    $this->actingAs($this->seller)->get('/notifications')->assertOk()
        ->assertSee('New sale')->assertSee('Payout sent')->assertSee('Sale cancelled')
        ->assertSee('The stock ran out.')
        ->assertSee(route('selling.orders'), false);
});

it('still renders older notifications that only have a body and a link', function () {
    $this->buyer->notifications()->create([
        'id' => (string) Illuminate\Support\Str::uuid(),
        'type' => 'legacy',
        'data' => ['body' => 'Congratulations, a legacy body', 'link' => 'https://legacy.example/target'],
    ]);

    $this->actingAs($this->buyer)->get('/notifications')->assertOk()->assertSee('Congratulations, a legacy body')->assertSee('https://legacy.example/target', false);
});

it('marks a notification as read from the page', function () {
    $this->buyer->notify(new OrderReceipt($this->order));
    $id = $this->buyer->notifications()->first()->id;

    voltPage($this, $this->buyer, '/notifications', 'notifications')->call('delete', $id);

    expect($this->buyer->notifications()->whereKey($id)->exists())->toBeFalse();
});

it('builds every marketplace mail from the Laravel markdown components with a button, and an items table where items are listed', function () {
    foreach (marketplaceNotifications($this) as $kind => [$notification, $user]) {
        $mail = $notification->toMail($user);
        $html = (string) $mail->render();

        expect($mail->subject)->toContain($this->order->number)
            ->and($html)->toContain($this->order->number)
            ->and($html)->toContain('<table')            // the markdown layout
            ->and($html)->toContain('class="button')     // the mail::button component
            ->and($html)->toContain(config('app.name'));

        if ($kind !== 'payout_sent') {
            expect($html)->toContain('<thead')->toContain('Notified Woofer');   // the items table
        }
    }
});

it('links the mails to the order page and the sales page', function () {
    $buyerMail = (string) (new OrderShipped($this->so))->toMail($this->buyer)->render();
    expect($buyerMail)->toContain('https://ups.example/1Z999AA1');

    $receipt = (string) (new OrderReceipt($this->order))->toMail($this->buyer)->render();
    expect($receipt)->toContain(route('dashboard.orders.show', ['order' => $this->order->number]));

    $sale = (string) (new NewSale($this->so))->toMail($this->seller)->render();
    expect($sale)->toContain(route('selling.orders'));
});
