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
use Illuminate\Support\Str;
use Wave\Notifications\VerifyEmail;

/*
 * The "sdlabs" mail theme: a brand-colour header band with the logo and the name, brand buttons, a footer with the site
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

it('has the header band with the logo and the name, and the footer with the site links', function () {
    $html = themed((new MailMessage)->subject('x')->greeting('Hello')->line('Body')->action('Open it', 'https://example.test/x'));

    expect($html)
        // header: a band in the brand colour (inlined from the theme), the name as a text link, the amber rule
        ->toContain('class="header-band"')->toContain('background-color: #3b0764')
        ->toContain('class="wordmark"')->toContain('>SDLabs.cc</a>')
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
        // no web fonts, no CSS variables, and the logo is the only image
        ->not->toContain('var(--')->not->toContain('fonts.googleapis')
        ->and(substr_count($html, '<img'))->toBe(1);
});

it('shows the site logo and "SDLabs.cc" in the header, and no tagline there', function () {
    $html = themed((new MailMessage)->subject('x')->line('Body'));
    $header = Str::between($html, 'class="header-band"', 'class="header-accent"');
    $logo = public_path('images/email/logo.png');

    expect($header)
        // a PNG (mail clients block SVG) at an absolute URL, with the name next to it as text so it reads without images
        ->toContain('src="'.asset('images/email/logo.png').'"')->toContain('alt="SDLabs.cc"')->toContain('>SDLabs.cc</a>')
        ->not->toContain('The speaker design marketplace')
        ->and(asset('images/email/logo.png'))->toStartWith('http')
        // the file exists, at twice the 36 px it is shown at
        ->and(is_file($logo))->toBeTrue()
        ->and(getimagesize($logo)[0])->toBe(72)
        // the footer is as it was
        ->and($html)->toContain('footer-tagline');
});

it('keeps the layout responsive and supports dark mode', function () {
    $html = themed((new MailMessage)->line('Body'));

    expect($html)->toContain('name="viewport"')->toContain('max-width: 640px')->toContain('prefers-color-scheme: dark')
        ->toContain('name="color-scheme"');
});

it('names the site after the app name in the footer', function () {
    config(['app.name' => 'Acme Audio']);

    $html = themed((new MailMessage)->line('Body'));

    expect($html)->toContain('You\'re receiving this because you have an account at Acme Audio.')->not->toContain('Wave');
});

it('styles the tables of the order mails: header row, row lines, numbers on the right', function () {
    $buyer = User::factory()->create();
    $x = paidListingOrder($buyer, null, 6000, ['title' => 'Themed Woofer']);

    $html = (string) (new OrderReceipt($x['order']->fresh()))->toMail($buyer)->render();

    expect($html)->toContain('<thead')->toContain('Themed Woofer')
        // inlined from the theme: header cells on the light brand tint, body cells with a bottom rule
        ->toMatch('/<th[^>]*background-color: #faf5ff/')
        ->toMatch('/<td[^>]*border-bottom: 1px solid #e5e7eb/')
        ->toMatch('/<t[dh][^>]*align="right"/');
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
