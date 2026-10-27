<?php

namespace App\Filament\Pages;

use App\Enums\LedgerLink;
use App\Filament\Resources\OrderResource;
use App\Filament\Widgets\SellerBalances;
use App\Jobs\SyncStripeLedger;
use App\Models\SellerProfile;
use App\Models\StripeBalance;
use App\Models\StripeBalanceTransaction;
use App\Models\StripeSyncCursor;
use App\Services\Marketplace\Ledger\LedgerSync;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use App\Support\StripeMode;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin > Finance > Stripe ledger (docs/PLAN.md 11, R6.2): Stripe's own books, mirrored. The platform's balance per currency
 * (available, pending, on its way to the bank) with the time of the last sync and a "Sync now" button, every balance
 * transaction with filters, links to the orders and to Stripe, a CSV export, and below it the balances of the sellers. The
 * numbers come from the mirror (LedgerSync), not from the site's own order records.
 */
class StripeLedger extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'phosphor-vault-duotone';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Stripe ledger';

    protected static ?string $title = 'Stripe ledger';

    protected static ?string $slug = 'stripe-ledger';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.stripe-ledger';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('admin');
    }

    /** @return array<int, class-string> */
    protected function getFooterWidgets(): array
    {
        return [SellerBalances::class];
    }

    public function getFooterWidgetsColumns(): int|string|array
    {
        return 1;
    }

    // --- What the cards show ------------------------------------------------------------------------------------------------------

    /**
     * The platform balance, one row per currency (the marketplace currency first).
     *
     * @return Collection<int, StripeBalance>
     */
    public function balances(): Collection
    {
        $main = strtolower((string) config('marketplace.currency', 'usd'));

        return StripeBalance::query()->forAccount(null)->currentMode()->get()
            ->sortBy(fn (StripeBalance $balance) => ($balance->currency === $main ? '0' : '1').$balance->currency)->values();
    }

    public function platformCursor(): ?StripeSyncCursor
    {
        return StripeSyncCursor::query()->where(['account_key' => 'platform', 'livemode' => StripeMode::isLive()])->first();
    }

    /**
     * Seller accounts whose last sync failed, for the warning above the table.
     *
     * @return Collection<int, StripeSyncCursor>
     */
    public function failingAccounts(): Collection
    {
        return StripeSyncCursor::query()->where('livemode', StripeMode::isLive())->whereNotNull('last_error')->where('account_key', '!=', 'platform')->orderBy('account_key')->get();
    }

    // --- Actions ------------------------------------------------------------------------------------------------------------------

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Sync now')
                ->icon('heroicon-m-arrow-path')
                ->action(fn () => $this->syncNow()),
            Action::make('export')
                ->label('Export CSV')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    /**
     * Read the platform's books now (a bounded number of pages; the rest is queued) and queue a sync of every seller account.
     * A Stripe error is shown instead of thrown.
     */
    public function syncNow(): void
    {
        $sync = app(LedgerSync::class);

        if (! $sync->enabled()) {
            Notification::make()->warning()->title('Stripe is not configured')->body('Set STRIPE_SECRET_KEY to read the ledger.')->send();

            return;
        }

        try {
            $result = $sync->syncAccount(null, maxPages: max(1, (int) config('marketplace.ledger.job_max_pages', 20)));
        } catch (PaymentGatewayException $e) {
            Notification::make()->danger()->title('Could not read Stripe')->body($e->getMessage())->send();

            return;
        }

        if (! $result->complete) {
            SyncStripeLedger::queueFor(null);
        }

        $sellers = collect($sync->accounts())->filter()->filter(fn (string $account) => SyncStripeLedger::queueFor($account))->count();

        Notification::make()->success()->title('Synced with Stripe')
            ->body($result->transactions.' '.str('transaction')->plural($result->transactions).' read'.($sellers > 0 ? "; {$sellers} seller ".str('account')->plural($sellers).' are syncing in the background.' : '.').($result->complete ? '' : ' The rest of the history follows in the background.'))
            ->send();
    }

    /** The transactions the table shows now (filters and search applied) as a CSV download, newest first, money in dollars. */
    public function exportCsv(): StreamedResponse
    {
        $query = $this->getTableQueryForExport()->reorder()->orderByDesc('stripe_created_at')->orderByDesc('id');
        $money = fn (int $cents): string => number_format($cents / 100, 2, '.', '');
        // A cell that starts with = + - or @ would be a formula in a spreadsheet.
        $text = fn (?string $value): string => $value !== null && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : (string) $value;
        $shops = SellerProfile::query()->whereNotNull('stripe_account_id')->pluck('shop_name', 'stripe_account_id');

        return response()->streamDownload(function () use ($query, $money, $text, $shops) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date (UTC)', 'Account', 'Type', 'Reporting category', 'Amount', 'Fee', 'Net', 'Currency', 'Available on (UTC)', 'Status', 'Order', 'Link', 'Description', 'Stripe transaction', 'Stripe object'], escape: '');
            $orders = [];

            foreach ($query->cursor() as $row) {
                /** @var StripeBalanceTransaction $row */
                $order = $row->order_id === null ? null : ($orders[$row->order_id] ??= \App\Models\Order::query()->whereKey($row->order_id)->value('number'));

                fputcsv($out, [
                    $row->stripe_created_at->utc()->format('Y-m-d H:i:s'),
                    $row->account === null ? 'Platform' : $text($shops[$row->account] ?? $row->account),
                    $row->typeLabel(),
                    $row->reporting_category,
                    $money($row->amount_cents),
                    $money($row->fee_cents),
                    $money($row->net_cents),
                    strtoupper($row->currency),
                    $row->available_on->utc()->format('Y-m-d H:i:s'),
                    $row->isPending() ? 'pending' : 'available',
                    $order,
                    $row->link_status->label(),
                    $text($row->description),
                    $row->getKey(),
                    $row->source_id,
                ], escape: '');
            }

            fclose($out);
        }, 'stripe-ledger-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // --- The transactions ---------------------------------------------------------------------------------------------------------

    public function table(Table $table): Table
    {
        return $table
            ->query(StripeBalanceTransaction::query()->currentMode()->with(['order', 'seller.sellerProfile']))
            ->heading('Transactions')
            ->description('Every line of Stripe\'s ledger for the platform and the sellers\' accounts. Money in is positive, money out negative.')
            ->defaultSort('stripe_created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\TextColumn::make('stripe_created_at')->label('Date')->dateTime('M j, Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->state(fn (StripeBalanceTransaction $record): string => $record->typeLabel())
                    ->color(fn (StripeBalanceTransaction $record): string => match (true) {
                        str_ends_with($record->type, '_failure') || $record->type === 'adjustment' && $record->amount_cents < 0 => 'danger',
                        $record->amount_cents < 0 => 'warning',
                        default => 'success',
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('account')
                    ->label('Account')
                    ->state(fn (StripeBalanceTransaction $record): string => static::accountName($record))
                    ->description(fn (StripeBalanceTransaction $record): ?string => $record->account)
                    ->searchable(),
                Tables\Columns\TextColumn::make('order.number')
                    ->label('Order')
                    ->searchable()
                    ->weight('medium')
                    ->url(fn (StripeBalanceTransaction $record): ?string => $record->order ? OrderResource::getUrl('view', ['record' => $record->order]) : null)
                    ->description(fn (StripeBalanceTransaction $record): ?string => $record->link_status === LedgerLink::UNLINKED ? 'not linked' : null)
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('description')->limit(30)->tooltip(fn (StripeBalanceTransaction $record): ?string => $record->description)->searchable()->placeholder('-')->toggleable(),
                Tables\Columns\TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, StripeBalanceTransaction $record): string => money((int) $state, $record->currency))
                    ->color(fn (StripeBalanceTransaction $record): ?string => $record->amount_cents < 0 ? 'danger' : null)
                    ->sortable()
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('fee_cents')
                    ->label('Fee')
                    ->formatStateUsing(fn ($state, StripeBalanceTransaction $record): string => $record->fee_cents === 0 ? '-' : money((int) $state, $record->currency))
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('net_cents')
                    ->label('Net')
                    ->formatStateUsing(fn ($state, StripeBalanceTransaction $record): string => money((int) $state, $record->currency))
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('available_on')
                    ->label('Available')
                    ->state(fn (StripeBalanceTransaction $record): string => $record->isPending() ? 'on '.$record->available_on->format('M j') : 'available')
                    ->badge()
                    ->color(fn (StripeBalanceTransaction $record): string => $record->isPending() ? 'warning' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('id')->label('Transaction')->copyable()->limit(18)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->multiple()
                    ->options(fn (): array => StripeBalanceTransaction::query()->currentMode()->distinct()->orderBy('type')->pluck('type')
                        ->mapWithKeys(fn (string $type) => [$type => (new StripeBalanceTransaction(['type' => $type]))->typeLabel()])->all()),
                Tables\Filters\SelectFilter::make('account')
                    ->label('Account')
                    ->searchable()
                    ->options(fn (): array => ['platform' => 'Platform'] + SellerProfile::query()->whereNotNull('stripe_account_id')->orderBy('shop_name')->pluck('shop_name', 'stripe_account_id')->all())
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'platform' => $query->whereNull('account'),
                        default => $query->where('account', $data['value']),
                    }),
                Tables\Filters\Filter::make('date')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->where('stripe_created_at', '>=', \Illuminate\Support\Carbon::parse($date)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->where('stripe_created_at', '<=', \Illuminate\Support\Carbon::parse($date)->endOfDay()))),
                Tables\Filters\SelectFilter::make('link_status')
                    ->label('Link')
                    ->options(LedgerLink::options()),
            ])
            ->actions([
                Tables\Actions\Action::make('stripe')
                    ->label('Open in Stripe')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->iconButton()
                    ->tooltip('Open in Stripe')
                    ->color('gray')
                    ->url(fn (StripeBalanceTransaction $record): string => $record->stripeUrl())
                    ->openUrlInNewTab(),
            ])
            ->emptyStateIcon('phosphor-vault')
            ->emptyStateHeading('Nothing read from Stripe yet')
            ->emptyStateDescription('Press "Sync now", or run php artisan stripe:ledger-sync --all once to read the whole history.');
    }

    /** "Platform", or the shop of the seller whose connected account the line is on. */
    public static function accountName(StripeBalanceTransaction $row): string
    {
        return $row->account === null ? 'Platform' : ($row->seller?->sellerProfile?->shop_name ?? $row->account);
    }
}
