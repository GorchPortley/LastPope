<?php

namespace App\Filament\Resources;

use App\Enums\DiscrepancyKind;
use App\Filament\Resources\StripeDiscrepancyResource\Pages;
use App\Models\StripeDiscrepancy;
use App\Support\StripeMode;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin > Finance > Reconciliation (docs/PLAN.md 11, R6.3): the differences the daily check found between the site's records
 * and Stripe's ledger. Open ones first; a difference closes by itself when a later check finds it fixed, or an admin closes it
 * with a note. Nothing is created or edited by hand. The badge counts the open ones.
 */
class StripeDiscrepancyResource extends Resource
{
    protected static ?string $model = StripeDiscrepancy::class;

    protected static ?string $slug = 'reconciliation';

    protected static ?string $navigationIcon = 'phosphor-scales-duotone';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Reconciliation';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'difference';

    protected static ?string $pluralModelLabel = 'differences';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasRole('admin');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /** Differences nobody has dealt with. */
    public static function getNavigationBadge(): ?string
    {
        $count = static::openCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Differences between the site and Stripe that are still open';
    }

    public static function openCount(): int
    {
        return StripeDiscrepancy::query()->open()->where('livemode', StripeMode::isLive())->count();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('livemode', StripeMode::isLive());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order', 'sellerOrder', 'resolver']))
            ->columns([
                Tables\Columns\TextColumn::make('kind')
                    ->label('Difference')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => static::kind($state)->label())
                    ->color(fn ($state): string => static::kind($state)->color())
                    ->searchable(false),
                Tables\Columns\TextColumn::make('order.number')
                    ->label('Order')
                    ->searchable()
                    ->weight('medium')
                    ->url(fn (StripeDiscrepancy $record): ?string => $record->order ? OrderResource::getUrl('view', ['record' => $record->order]) : null)
                    ->description(fn (StripeDiscrepancy $record): ?string => $record->stripe_id)
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('details')
                    ->wrap()
                    ->extraAttributes(['class' => 'min-w-[14rem]'])
                    ->searchable(),
                Tables\Columns\TextColumn::make('expected_cents')
                    ->label('Site')
                    ->formatStateUsing(fn ($state, StripeDiscrepancy $record): string => money((int) $state, $record->currency))
                    ->placeholder('-')
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('actual_cents')
                    ->label('Stripe')
                    ->formatStateUsing(fn ($state, StripeDiscrepancy $record): string => money((int) $state, $record->currency))
                    ->placeholder('-')
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('first_seen_at')->label('First seen')->dateTime('M j, H:i')->sortable(),
                Tables\Columns\TextColumn::make('last_seen_at')->label('Last seen')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (StripeDiscrepancy $record): string => $record->isOpen() ? 'Open' : ($record->resolution === 'auto' ? 'Fixed' : 'Closed'))
                    ->color(fn (StripeDiscrepancy $record): string => $record->isOpen() ? 'danger' : ($record->resolution === 'auto' ? 'success' : 'gray'))
                    ->description(fn (StripeDiscrepancy $record): ?string => $record->isOpen() ? null : $record->resolved_at->format('M j, Y').($record->resolver ? ' by '.$record->resolver->name : '')),
                Tables\Columns\TextColumn::make('note')->wrap()->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('first_seen_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('kind')->label('Difference')->options(DiscrepancyKind::options())->multiple(),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Open in Stripe')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->iconButton()
                    ->tooltip('Open in Stripe')
                    ->color('gray')
                    ->visible(fn (StripeDiscrepancy $record): bool => filled($record->stripe_id))
                    ->url(fn (StripeDiscrepancy $record): string => static::stripeUrl($record))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('resolve')
                    ->label('Close')
                    ->icon('heroicon-m-check')
                    ->visible(fn (StripeDiscrepancy $record): bool => $record->isOpen())
                    ->modalHeading('Close this difference')
                    ->modalDescription('Say what you did about it. It stays closed while the numbers stay the same, and opens again if they change.')
                    ->modalSubmitActionLabel('Close difference')
                    ->form([Textarea::make('note')->label('Note')->required()->maxLength(2000)->rows(4)])
                    ->action(function (array $data, StripeDiscrepancy $record): void {
                        $record->forceFill([
                            'resolved_at' => now(),
                            'resolved_by' => auth()->id(),
                            'resolution' => 'manual',
                            'note' => $data['note'],
                        ])->save();

                        Notification::make()->success()->title('Difference closed')->send();
                    }),
                Tables\Actions\Action::make('reopen')
                    ->label('Open again')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (StripeDiscrepancy $record): bool => ! $record->isOpen())
                    ->requiresConfirmation()
                    ->action(function (StripeDiscrepancy $record): void {
                        $record->forceFill(['resolved_at' => null, 'resolved_by' => null, 'resolution' => null, 'note' => null])->save();

                        Notification::make()->success()->title('Difference opened again')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStripeDiscrepancies::route('/'),
        ];
    }

    /** Where to look at it in Stripe: the transfer, payout, dispute or payment its id names. */
    public static function stripeUrl(StripeDiscrepancy $record): string
    {
        $id = (string) $record->stripe_id;
        $path = match (true) {
            str_starts_with($id, 'tr_') => 'connect/transfers/'.$id,
            str_starts_with($id, 'po_') => 'payouts/'.$id,
            str_starts_with($id, 'du_'), str_starts_with($id, 'dp_') => 'disputes/'.$id,
            default => 'payments/'.$id,
        };

        return StripeMode::dashboardUrl($path, (bool) $record->livemode);
    }

    private static function kind(mixed $state): DiscrepancyKind
    {
        return $state instanceof DiscrepancyKind ? $state : DiscrepancyKind::from((string) $state);
    }
}
