<?php

use App\Filament\Pages\EmailPreviews;
use App\Mail\PurchaseOrderMail;
use App\Models\Design;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderIssue;
use App\Models\SellerOrder;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Support\EmailPreviews\EmailPreviews as Catalogue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Admin > Settings > Email previews: every e-mail rendered with made-up data, in an iframe each.
 */

it('opens for admins and lists every e-mail with its subject and an iframe', function () {
    $response = $this->actingAs(adminUser())->get('/admin/email-previews')->assertOk();

    $response->assertSee('Email previews')->assertSee('Order receipt, several parcels')->assertSee('Problem reported')
        ->assertSee('Purchase order')->assertSee('Reset your password')->assertSee('Verify your e-mail')
        ->assertSee('Your order SDL-', false)->assertSee('srcdoc="', false)->assertSee('sandbox="allow-popups', false);

    // the page is in the Settings group next to Appearance
    expect(EmailPreviews::getNavigationGroup())->toBe('Settings');
});

it('is not available to regular users or guests', function () {
    $this->actingAs(User::factory()->create())->get('/admin/email-previews')->assertForbidden();

    auth()->logout();
    $this->get('/admin/email-previews')->assertRedirect();

    expect(EmailPreviews::canAccess())->toBeFalse();
});

it('renders every preview without errors, in the theme', function () {
    $previews = app(Catalogue::class)->renderAll(adminUser());

    expect($previews)->not->toBeEmpty();

    foreach ($previews as $key => $preview) {
        expect($preview['error'])->toBeNull("{$key}: {$preview['error']}");
        expect($preview['subject'])->not->toBeEmpty()
            ->and($preview['to'])->not->toBeEmpty()
            ->and($preview['html'])->toContain('class="header-band"')->toContain('site-footer')->toContain('background-color: #3b0764');
    }
});

it('covers every e-mail the app sends', function () {
    $sources = collect(app(Catalogue::class)->catalogue())->pluck('source')->unique()->all();

    // notifications with a mail channel, the mailable, the account mails
    $mailNotifications = collect(glob(app_path('Notifications/*.php')))
        ->map(fn ($file) => 'App\\Notifications\\'.basename($file, '.php'))
        ->filter(fn ($class) => (new ReflectionClass($class))->isInstantiable())
        ->filter(fn ($class) => in_array('mail', (new ReflectionClass($class))->newInstanceWithoutConstructor()->via(new User()), true))
        ->values()->all();

    expect($mailNotifications)->not->toBeEmpty();

    foreach ([...$mailNotifications, PurchaseOrderMail::class, Wave\Notifications\VerifyEmail::class, Illuminate\Auth\Notifications\ResetPassword::class, Illuminate\Auth\Notifications\VerifyEmail::class] as $class) {
        expect($sources)->toContain($class);
    }
});

it('tells every preview where it comes from, with a plain-text version for notifications', function () {
    $previews = app(Catalogue::class)->renderAll(adminUser());

    expect($previews['order_receipt_parcels']['source'])->toBe(App\Notifications\OrderReceipt::class)
        ->and($previews['order_receipt_parcels']['text'])->toContain('The speaker design marketplace')->toContain('Ships separately')
        ->and($previews['purchase_order']['to'])->toBe('orders@northwind-audio.example.test')
        ->and($previews['purchase_order']['subject'])->toStartWith('Purchase order PO-')
        ->and($previews['order_shipped_parcels']['subject'])->toContain('(parcel 1 of 2)');
});

it('keeps nothing: no rows, no notifications, no mail, no jobs', function () {
    Notification::fake();
    Mail::fake();
    Queue::fake();
    $admin = adminUser();
    $tables = [User::class, Order::class, SellerOrder::class, Listing::class, Design::class, Supplier::class, SupplierOrder::class, OrderIssue::class, DatabaseNotification::class];
    $before = array_map(fn ($model) => $model::count(), $tables);

    app(Catalogue::class)->renderAll($admin);

    expect(array_map(fn ($model) => $model::count(), $tables))->toBe($before);
    Notification::assertNothingSent();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Queue::assertNothingPushed();
});

it('shows the sample data as a made-up shop, never real people', function () {
    $html = collect(app(Catalogue::class)->renderAll(adminUser()))->pluck('html')->implode("\n");

    expect($html)->toContain('Alex Rivera')->toContain('Hale Acoustics')->not->toContain('@sdlabs.test');
});

it('rolls the sample data back even when a preview fails', function () {
    $admin = adminUser();
    $users = User::count();

    // a catalogue whose first e-mail cannot be built
    $broken = new class extends Catalogue
    {
        public function definitions(): array
        {
            return ['boom' => [
                'source' => 'Nothing', 'group' => 'Test', 'label' => 'Boom', 'audience' => 'None', 'description' => 'Fails.',
                'build' => fn () => throw new RuntimeException('the template is broken'),
            ]];
        }
    };

    $previews = $broken->renderAll($admin);

    expect($previews['boom']['error'])->toBe('the template is broken')->and($previews['boom']['html'])->toBeNull()
        ->and(User::count())->toBe($users);

    $this->actingAs($admin);
});

it('shows a failing e-mail as an error box instead of breaking the page', function () {
    $this->mock(Catalogue::class, function ($mock) {
        $mock->shouldReceive('renderAll')->andReturn(['boom' => [
            'source' => 'Nothing', 'group' => 'Test', 'label' => 'Boom', 'audience' => 'None', 'description' => 'Fails.',
            'subject' => null, 'to' => null, 'html' => null, 'text' => null, 'error' => 'the template is broken',
        ]]);
    });

    $this->actingAs(adminUser())->get('/admin/email-previews')->assertOk()->assertSee('This e-mail could not be rendered: the template is broken');
});
