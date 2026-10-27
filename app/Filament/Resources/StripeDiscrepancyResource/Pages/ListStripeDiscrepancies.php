<?php

namespace App\Filament\Resources\StripeDiscrepancyResource\Pages;

use App\Filament\Resources\StripeDiscrepancyResource;
use App\Models\StripeDiscrepancy;
use App\Services\Marketplace\Ledger\Reconciler;
use App\Support\StripeMode;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListStripeDiscrepancies extends ListRecords
{
    protected static string $resource = StripeDiscrepancyResource::class;

    protected static ?string $title = 'Reconciliation';

    public function getSubheading(): ?string
    {
        return 'The shop\'s records against Stripe\'s ledger, checked every night. Open differences need a look; the others were fixed or closed.';
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('check')
                ->label('Check now')
                ->icon('heroicon-m-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    // On demand: compares with the ledger as it is (the Stripe ledger page syncs it); the nightly run mails the admins.
                    $result = app(Reconciler::class)->run(notify: false);

                    if ($result->skipped !== []) {
                        Notification::make()->warning()->title('Not checked')->body(implode(' ', $result->skipped))->send();

                        return;
                    }

                    Notification::make()
                        ->title($result->open->isEmpty() ? 'Everything agrees' : $result->open->count().' open '.str('difference')->plural($result->open->count()))
                        ->body($result->autoResolved > 0 ? $result->autoResolved.' closed because they are fixed.' : null)
                        ->{$result->open->isEmpty() ? 'success' : 'warning'}()
                        ->send();
                }),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $live = StripeMode::isLive();
        $count = fn (string $scope): int => StripeDiscrepancy::query()->where('livemode', $live)->{$scope}()->count();

        return [
            'open' => Tab::make('Open')
                ->badge($count('open'))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->open()),
            'resolved' => Tab::make('Fixed or closed')
                ->modifyQueryUsing(fn (Builder $query) => $query->resolved()),
            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }
}
