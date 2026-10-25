<?php

use App\Models\User;
use App\Notifications\OrderReceipt;
use App\Notifications\OrderRefunded;
use App\Notifications\OrderShipped;
use App\Support\RecipientLink;
use Illuminate\Support\Facades\Storage;

/*
| Links in e-mails and notifications are marked for the account they were sent to (RecipientLink). Opening one while
| signed in as anybody else, an admin or a seller of the order included, shows "this link is for another account"
| instead of the page; the buyer's own link works as before.
*/

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('private');
    config(['marketplace.physical_payout_trigger' => 'shipped']);
    $this->buyer = User::factory()->create();
    $this->x = paidListingOrder($this->buyer, null, 6000);
    $this->order = $this->x['order'];
    $this->link = (new OrderReceipt($this->order))->toMail($this->buyer)->actionUrl;
});

it('marks the order links of the buyer e-mails and notifications for the buyer', function () {
    $marked = RecipientLink::to(route('dashboard.orders.show', ['order' => $this->order->number]), $this->buyer);

    expect($this->link)->toBe($marked)
        ->and($marked)->toEndWith('?recipient='.$this->buyer->getKey())
        ->and((new OrderReceipt($this->order))->toArray($this->buyer)['url'])->toBe($marked)
        ->and((new OrderRefunded($this->order, 1000))->toMail($this->buyer)->actionUrl)->toBe($marked)
        ->and((new OrderShipped($this->x['sellerOrder']))->toArray($this->buyer)['url'])->toBe($marked);
});

it('opens the buyer link for the buyer', function () {
    $this->actingAs($this->buyer)->get($this->link)
        ->assertOk()
        ->assertSee('Order '.$this->order->number)
        ->assertSee($this->order->shipping_address['line1']);
});

it('does not show the buyer link to an admin or a seller of the order, who may still open the order without it', function (string $who) {
    $other = $who === 'admin' ? adminUser() : $this->x['seller'];

    $this->actingAs($other)->get($this->link)
        ->assertForbidden()
        ->assertSee('This link is for another account')
        ->assertSee($other->email)
        ->assertDontSee($this->order->shipping_address['line1'])
        ->assertDontSee($this->buyer->email);

    // their own way to the order (admin panel, sales list) is unchanged
    $this->actingAs($other)->get(route('dashboard.orders.show', ['order' => $this->order->number]))->assertOk();
})->with(['admin', 'seller']);

it('sends a guest to the login first and brings the buyer back to the order', function () {
    $this->get($this->link)->assertRedirectContains('login');

    $this->actingAs($this->buyer)->get($this->link)->assertOk()->assertSee('Order '.$this->order->number);
});

it('switches accounts: signs the wrong account out and returns to the link after the login', function () {
    $admin = adminUser();
    $path = parse_url($this->link, PHP_URL_PATH).'?'.parse_url($this->link, PHP_URL_QUERY);

    $this->actingAs($admin)
        ->post(route('link.switch-account'), ['to' => $path])
        ->assertRedirect(route('login'))
        ->assertSessionHas('url.intended', url($path));

    $this->assertGuest();
});

it('only switches back to a page of this site', function (string $to) {
    $admin = adminUser();

    $this->actingAs($admin)
        ->post(route('link.switch-account'), ['to' => $to])
        ->assertSessionHasErrors('to');

    $this->assertAuthenticatedAs($admin);
})->with(['https://evil.example/x', '//evil.example/x', '/\\evil.example', '']);

it('marks problem-report links for their recipient too', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    $link = (new App\Notifications\IssueOpened($issue))->toMail($x['seller'])->actionUrl;

    expect($link)->toEndWith('?recipient='.$x['seller']->getKey());

    $this->actingAs($x['buyer'])->get($link)->assertForbidden()->assertSee('This link is for another account');
    $this->actingAs($x['seller'])->get($link)->assertOk();
});
