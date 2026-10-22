<?php

use App\Mail\PurchaseOrderMail;
use App\Models\User;
use App\Notifications\NewSale;
use App\Notifications\OrderReceipt;
use App\Notifications\OrderShipped;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Wave\Notifications\VerifyEmail;

/*
 * The "sdlabs" mail theme: a brand-colour header band with the wordmark as text, brand buttons, a footer with the site
 * links, and a businesslike variant for the purchase order to a supplier.
 */

function themed(MailMessage $message): string
{
    return (string) $message->render();
}

it('uses the sdlabs theme for all markdown mail', function () {
    expect(config('mail.markdown.theme'))->toBe('sdlabs')
        ->and(file_exists(resource_path('views/vendor/mail/html/themes/sdlabs.css')))->toBeTrue();
});

it('has the header band with the wordmark as text and the footer with the site links', function () {
    $html = themed((new MailMessage)->subject('x')->greeting('Hello')->line('Body')->action('Open it', 'https://example.test/x'));

    expect($html)
        // header: a band in the brand colour (inlined from the theme), the wordmark as a text link, the amber rule
        ->toContain('class="header-band"')->toContain('background-color: #3b0764')
        ->toContain('class="wordmark"')->toContain('>SDLabs</a>')
        ->toContain('The speaker design marketplace')
        ->toContain('class="header-accent"')
        // footer: name, tagline, the four links, why you got it
        ->toContain('site-footer')
        ->toMatch('/<a href="'.preg_quote(route('designs'), '/').'"[^>]*>Designs<\/a>/')
        ->toMatch('/<a href="'.preg_quote(route('drivers'), '/').'"[^>]*>Drivers<\/a>/')
        ->toMatch('/<a href="'.preg_quote(route('marketplace'), '/').'"[^>]*>Marketplace<\/a>/')
        ->toMatch('/<a href="'.preg_quote(route('dashboard'), '/').'"[^>]*>Dashboard<\/a>/')
        ->toContain("You're receiving this because you have an account at SDLabs.")
        ->toContain('© '.date('Y').' SDLabs')
        // button in the brand colour, inlined (works without the stylesheet)
        ->toContain('button button-primary')->toContain('background-color: #7e22ce')
        // no web fonts, no remote images, no CSS variables
        ->not->toContain('var(--')->not->toContain('<img')->not->toContain('fonts.googleapis');
});

it('keeps the layout responsive and supports dark mode', function () {
    $html = themed((new MailMessage)->line('Body'));

    expect($html)->toContain('name="viewport"')->toContain('max-width: 640px')->toContain('prefers-color-scheme: dark')
        ->toContain('name="color-scheme"');
});

it('names the site after the app name, in the header and the footer', function () {
    config(['app.name' => 'Acme Audio']);

    $html = themed((new MailMessage)->line('Body'));

    expect($html)->toContain('>Acme Audio</a>')->toContain('You\'re receiving this because you have an account at Acme Audio.')->not->toContain('Wave');
});

it('styles the tables of the order mails: header row, row lines, numbers on the right', function () {
    $buyer = User::factory()->create();
    $x = paidListingOrder($buyer, null, 6000, ['title' => 'Themed Woofer']);

    $html = (string) (new OrderReceipt($x['order']->fresh()))->toMail($buyer)->render();

    expect($html)->toContain('<thead')->toContain('Themed Woofer')
        // inlined from the theme: header cells on the light brand tint, body cells with a bottom rule
        ->toMatch('/<th[^>]*background-color: #faf5ff/')
        ->toMatch('/<td[^>]*border-bottom: 1px solid #e5e7eb/')
        ->toContain('text-align: right');
});

it('themes the verification, reset and Wave mails too', function () {
    $user = User::factory()->create(['verification_code' => 'abc123']);

    foreach ([
        (new VerifyEmail($user))->toMail($user),
        (new ResetPassword('token'))->toMail($user),
    ] as $message) {
        expect(themed($message))->toContain('class="header-band"')->toContain('site-footer')->toContain('button-primary');
    }
});

it('has a plain-text version with the same footer', function () {
    $text = (string) app(Markdown::class)->renderText('notifications::email', (new MailMessage)->greeting('Hello')->line('Body')->action('Open it', 'https://example.test/x')->data());

    expect($text)->toContain('The speaker design marketplace')->toContain('Designs: '.route('designs'))->toContain("You're receiving this because you have an account at SDLabs.");
});

it('sends the supplier the same theme but businesslike: no marketing links, no tagline', function () {
    $po = purchaseOrderOf(paidOrderOf(User::factory()->create(), [[dropshipListing(supplier(['order_email' => 'orders@acme.example.test']), ['supplier_stock' => 5], adminUser()), 1]]));

    $mailable = new PurchaseOrderMail($po->fresh());
    $html = $mailable->render();

    expect($html)->toContain('class="header-band"')->toContain('background-color: #3b0764')->toContain('class="wordmark"')
        ->toContain('Purchase order')
        ->toContain('Sent by SDLabs. Just reply to this e-mail to reach us.')
        ->not->toContain('The speaker design marketplace')
        ->not->toContain(route('designs'))->not->toContain(route('marketplace'))->not->toContain(route('dashboard'))
        ->not->toContain("You're receiving this because")
        // still the supplier's table, with brand styling
        ->toContain('<thead')->toContain($po->number)
        // and the reply-to is the store's address, as before
        ->and($mailable->envelope()->replyTo[0]->address)->toBe(PurchaseOrderMail::replyToAddress());
});

it('shows the order mails with a shipped parcel and a button', function () {
    $buyer = User::factory()->create();
    $x = paidListingOrder($buyer, null, 6000);
    app(ShipmentService::class)->markShipped($x['seller'], $x['sellerOrder'], 'UPS', '1Z999AA1', 'https://ups.example/1Z999AA1');
    $sellerOrder = $x['sellerOrder']->fresh(['order', 'items']);

    $shipped = (string) (new OrderShipped($sellerOrder))->toMail($buyer)->render();
    $sale = (string) (new NewSale($sellerOrder))->toMail($x['seller'])->render();

    expect($shipped)->toContain('https://ups.example/1Z999AA1')->toContain('button-primary')->toContain('class="header-band"')
        ->and($sale)->toContain('class="header-band"')->toContain('site-footer');
});
