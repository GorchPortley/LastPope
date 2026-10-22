<?php

namespace App\Filament\Pages;

use App\Services\Marketplace\Data\MarginRow;
use App\Services\Marketplace\MarginReport;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin > Store > Margins (docs/PLAN.md 8.4): what the SDLabs store earned in a date range. Totals (revenue, costs, Stripe
 * fees, refunds, margin, margin %), one row per paid order, and a CSV download. The numbers come from MarginReport.
 */
class Margins extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'phosphor-chart-line-up-duotone';

    protected static ?string $navigationGroup = 'Store';

    protected static ?string $navigationLabel = 'Margins';

    protected static ?string $title = 'Margins';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.margins';

    public const PRESETS = [
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'last_30_days' => 'Last 30 days',
        'custom' => 'Custom range',
    ];

    /** @var array{preset: string, from: ?string, until: ?string} */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('admin');
    }

    public function mount(): void
    {
        [$from, $until] = static::presetRange('this_month');

        $this->form->fill(['preset' => 'this_month', 'from' => $from->toDateString(), 'until' => $until->toDateString()]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->columns(['default' => 1, 'sm' => 3])
            ->schema([
                Select::make('preset')
                    ->label('Period')
                    ->options(self::PRESETS)
                    ->native(false)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if ($state !== null && $state !== 'custom') {
                            [$from, $until] = static::presetRange($state);
                            $set('from', $from->toDateString());
                            $set('until', $until->toDateString());
                        }
                    }),
                DatePicker::make('from')
                    ->label('From')
                    ->native(false)
                    ->live()
                    ->maxDate(now())
                    ->visible(fn (Get $get): bool => $get('preset') === 'custom'),
                DatePicker::make('until')
                    ->label('Until (included)')
                    ->native(false)
                    ->live()
                    ->maxDate(now())
                    ->visible(fn (Get $get): bool => $get('preset') === 'custom'),
            ]);
    }

    /**
     * The first and last day of a preset.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function presetRange(string $preset): array
    {
        $today = CarbonImmutable::today();

        return match ($preset) {
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'last_30_days' => [$today->subDays(29), $today],
            default => [$today->startOfMonth(), $today],
        };
    }

    /**
     * The days the report covers: the preset's, or the custom dates (swapped when entered the wrong way round, a missing
     * end meaning today).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $preset = (string) ($this->data['preset'] ?? 'this_month');

        if ($preset !== 'custom') {
            return static::presetRange(array_key_exists($preset, self::PRESETS) ? $preset : 'this_month');
        }

        $parse = function (?string $date, CarbonImmutable $fallback): CarbonImmutable {
            try {
                return filled($date) ? CarbonImmutable::parse($date)->startOfDay() : $fallback;
            } catch (\Throwable) {
                return $fallback;
            }
        };

        $today = CarbonImmutable::today();
        $from = $parse($this->data['from'] ?? null, $today->startOfMonth());
        $until = $parse($this->data['until'] ?? null, $today);

        return $from->greaterThan($until) ? [$until, $from] : [$from, $until];
    }

    /** @return array{rows: Collection<int, MarginRow>, totals: MarginRow} */
    public function report(): array
    {
        [$from, $until] = $this->range();

        return app(MarginReport::class)->forRange($from, $until);
    }

    public function rangeLabel(): string
    {
        [$from, $until] = $this->range();

        return $from->isSameDay($until) ? $from->format('M j, Y') : $from->format('M j, Y').' to '.$until->format('M j, Y');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadCsv')
                ->label('Download CSV')
                ->icon('heroicon-m-arrow-down-tray')
                ->action(fn (): StreamedResponse => $this->downloadCsv()),
        ];
    }

    public function downloadCsv(): StreamedResponse
    {
        [$from, $until] = $this->range();
        $csv = app(MarginReport::class)->toCsv($from, $until);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'margins-'.$from->toDateString().'-to-'.$until->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
