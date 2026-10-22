<?php

namespace App\Filament\Pages;

use App\Support\EmailPreviews\EmailPreviews as Catalogue;
use Filament\Pages\Page;

/**
 * Admin > Settings > Email previews: every e-mail the app sends, rendered with made-up data in the SDLabs mail theme
 * (resources/views/vendor/mail/html/themes/sdlabs.css), so the templates can be reviewed without sending anything.
 * The data is created in a transaction that is rolled back (App\Support\EmailPreviews\SampleData): nothing is kept and
 * nobody is e-mailed.
 */
class EmailPreviews extends Page
{
    protected static ?string $navigationIcon = 'phosphor-envelope-simple-duotone';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Email previews';

    protected static ?string $title = 'Email previews';

    protected static ?string $slug = 'email-previews';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'filament.pages.email-previews';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('admin');
    }

    /**
     * Every e-mail rendered, grouped for the page.
     *
     * @return array<string, array<string, array<string, mixed>>> group => key => preview
     */
    public function getGroups(): array
    {
        $previews = app(Catalogue::class)->renderAll(auth()->user());

        $groups = [];
        foreach ($previews as $key => $preview) {
            $groups[$preview['group']][$key] = $preview;
        }

        return $groups;
    }
}
