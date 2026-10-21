<?php

it('names the app SDLabs in .env.example', function () {
    expect(file_get_contents(base_path('.env.example')))->toContain("APP_NAME=SDLabs\n")->not->toContain('APP_NAME=Wave');
});

it('shows the configured app name in the footer and page titles instead of Wave', function () {
    config(['app.name' => 'Acme Audio']);

    $html = $this->get('/marketplace')->assertOk()->getContent();

    expect($html)->toContain('&copy; '.date('Y').' Acme Audio.')
        ->and($html)->toContain('Marketplace - Acme Audio')
        ->and($html)->not->toContain('Wave.');
});

it('has no leftover Wave copy in the theme components', function () {
    foreach (['footer', 'testimonials', 'header'] as $component) {
        expect(file_get_contents(resource_path("themes/blank/components/marketing/{$component}.blade.php")))->not->toContain('Wave');
    }
});

it('uses the app name in the mail layout', function () {
    config(['app.name' => 'Acme Audio']);

    $html = (string) (new Illuminate\Notifications\Messages\MailMessage)->subject('x')->line('Hello')->render();

    expect($html)->toContain('Acme Audio')->not->toContain('Wave');
});
