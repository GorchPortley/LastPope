<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\StripeLedger;
use App\Filament\Resources\StripeDiscrepancyResource;
use App\Models\StripeBalance;
use App\Models\StripeSyncCursor;
use App\Support\StripeMode;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Money and Stripe at the top of the admin dashboard (docs/PLAN.md 11): the open differences between the site and Stripe,
 * the platform's available balance and when the ledger was last read. The differences link to the Reconciliation page.
 */
class FinanceStats extends StatsOverviewWidget
{
    protected static ?int $sort = -9;

    protected ?string $heading = 'Stripe';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasRole('admin');
    }

    protected function getStats(): array
    {
        $open = StripeDiscrepancyResource::openCount();
        $cursor = StripeSyncCursor::query()->where(['account_key' => 'platform', 'livemode' => StripeMode::isLive()])->first();
        $balance = StripeBalance::query()->forAccount(null)->currentMode()->where('currency', strtolower((string) config('marketplace.currency', 'usd')))->first();

        return [
            Stat::make('Open Stripe differences', $open)
                ->icon('heroicon-o-scale')
                ->description($open > 0 ? 'the shop and Stripe disagree: have a look' : 'the shop and Stripe agree')
                ->descriptionColor($open > 0 ? 'danger' : 'success')
                ->color($open > 0 ? 'danger' : 'gray')
                ->url(StripeDiscrepancyResource::getUrl('index')),
            Stat::make('Stripe balance', $balance ? money($balance->available_cents, $balance->currency) : '-')
                ->icon('heroicon-o-building-library')
                ->description($balance ? money($balance->pending_cents, $balance->currency).' pending, '.money($balance->in_transit_cents, $balance->currency).' on its way to the bank' : 'not read from Stripe yet')
                ->url(StripeLedger::getUrl()),
            Stat::make('Ledger last read', $cursor?->last_synced_at ? $cursor->last_synced_at->diffForHumans() : 'never')
                ->icon('heroicon-o-arrow-path')
                ->description($cursor?->isFailing() ? 'the last attempt failed: '.\Illuminate\Support\Str::limit((string) $cursor->last_error, 60) : 'every ten minutes, and when Stripe reports a change')
                ->descriptionColor($cursor?->isFailing() ? 'danger' : 'gray')
                ->color($cursor?->isFailing() ? 'danger' : 'gray')
                ->url(StripeLedger::getUrl()),
        ];
    }
}
