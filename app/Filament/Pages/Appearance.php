<?php

namespace App\Filament\Pages;

use App\Support\SeasonalTheme;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Admin > Settings > Appearance: pick the seasonal theme of the public site (config/seasons.php).
 * The choice is switched by hand, there is no date-based switching. Saving stores the Wave setting
 * `site.season` (Wave's Setting model clears its settings cache, so the change is live on the next request).
 */
class Appearance extends Page
{
    protected static ?string $navigationIcon = 'phosphor-paint-brush-duotone';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Appearance';

    protected static ?string $title = 'Appearance';

    protected static ?int $navigationSort = 7;

    protected static string $view = 'filament.pages.appearance';

    /** The season selected in the form (not necessarily saved yet). */
    public string $season = SeasonalTheme::DEFAULT;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('admin');
    }

    public function mount(): void
    {
        $this->season = app(SeasonalTheme::class)->configuredKey();
    }

    public function save(): void
    {
        $theme = app(SeasonalTheme::class);

        $this->validate(['season' => ['required', 'string', 'in:'.implode(',', array_keys($theme->all()))]]);

        $theme->set($this->season);

        Notification::make()
            ->title('Season saved')
            ->body($theme->get($this->season)['label'].' is now live on the public site.')
            ->success()
            ->send();
    }

    /** The home page in the selected season; only admins see the preview. */
    public function previewUrl(): string
    {
        $theme = app(SeasonalTheme::class);

        return $theme->previewUrl($theme->has($this->season) ? $this->season : SeasonalTheme::DEFAULT);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->url(fn (): string => $this->previewUrl())
                ->openUrlInNewTab(),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function getSeasons(): array
    {
        $theme = app(SeasonalTheme::class);

        return collect($theme->all())
            ->map(fn (array $season, string $key) => $season + ['swatches' => $theme->swatches($key), 'preview_url' => $theme->previewUrl($key)])
            ->all();
    }

    public function getLiveSeason(): string
    {
        return app(SeasonalTheme::class)->configuredKey();
    }
}
