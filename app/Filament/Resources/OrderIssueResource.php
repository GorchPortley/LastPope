<?php

namespace App\Filament\Resources;

use App\Enums\IssueReason;
use App\Enums\IssueResolution;
use App\Enums\IssueStatus;
use App\Filament\Resources\OrderIssueResource\Pages;
use App\Models\OrderIssue;
use App\Services\Marketplace\PayoutService;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The queue of "Report a problem" cases (docs/PLAN.md 7, R2.2). Admins decide the escalated ones: reply, internal note,
 * full refund, partial refund, no action, and "Recover from seller" after a lost bank dispute, all through IssueService
 * with the admin as actor. The navigation badge counts the cases waiting for a decision.
 */
class OrderIssueResource extends Resource
{
    protected static ?string $model = OrderIssue::class;

    protected static ?string $navigationIcon = 'phosphor-warning-circle-duotone';

    protected static ?string $navigationGroup = 'Marketplace';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'problem report';

    protected static ?string $pluralModelLabel = 'problem reports';

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

    /** Cases waiting for a decision. */
    public static function getNavigationBadge(): ?string
    {
        $count = OrderIssue::query()->status(IssueStatus::ESCALATED)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Problem reports waiting for a decision';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order', 'sellerOrder', 'buyer', 'seller.sellerProfile']))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('order.number')
                    ->label('Order')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (OrderIssue $record): string => $record->created_at->format('M j, Y')),
                Tables\Columns\TextColumn::make('buyer.name')
                    ->label('Buyer')
                    ->searchable(['name', 'email'])
                    ->description(fn (OrderIssue $record): ?string => $record->buyer?->email),
                Tables\Columns\TextColumn::make('seller.name')
                    ->label('Seller')
                    ->searchable(['name', 'email'])
                    ->description(fn (OrderIssue $record): ?string => $record->seller?->sellerProfile?->shop_name),
                Tables\Columns\TextColumn::make('reason')
                    ->formatStateUsing(fn ($state): string => static::reason($state)->shortLabel())
                    ->description(fn (OrderIssue $record): ?string => $record->isDispute() ? 'Bank dispute' : null),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => static::status($state)->label())
                    ->color(fn ($state): string => static::status($state)->color()),
                Tables\Columns\TextColumn::make('resolution')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => static::resolution($state)->label())
                    ->color(fn ($state): string => static::resolution($state)->color())
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('refunded_cents')
                    ->label('Refunded')
                    ->formatStateUsing(fn ($state, OrderIssue $record): string => money((int) $state, $record->order->currency))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('escalated_at')->label('Escalated')->since()->sortable()->placeholder('-'),
                Tables\Columns\TextColumn::make('last_message_at')->label('Last activity')->since()->sortable()->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('Opened')->dateTime('M j, Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(IssueStatus::options())->multiple(),
                Tables\Filters\SelectFilter::make('reason')->options(IssueReason::options())->multiple(),
                Tables\Filters\SelectFilter::make('resolution')->options(IssueResolution::options())->multiple(),
                Tables\Filters\TernaryFilter::make('dispute')
                    ->label('Bank dispute')
                    ->placeholder('All cases')
                    ->trueLabel('With a bank dispute')
                    ->falseLabel('Without')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('stripe_dispute_id'),
                        false: fn (Builder $query) => $query->whereNull('stripe_dispute_id'),
                    ),
                Tables\Filters\Filter::make('recover')
                    ->label('Lost dispute, money not recovered')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('resolution', IssueResolution::CHARGEBACK_LOST->value)->whereNull('recovered_at')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Review'),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Case')
                    ->columns(4)
                    ->schema([
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => static::status($state)->label())
                            ->color(fn ($state): string => static::status($state)->color()),
                        Infolists\Components\TextEntry::make('reason')
                            ->formatStateUsing(fn ($state): string => static::reason($state)->label()),
                        Infolists\Components\TextEntry::make('resolution')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => static::resolution($state)->label())
                            ->color(fn ($state): string => static::resolution($state)->color())
                            ->placeholder('not resolved'),
                        Infolists\Components\TextEntry::make('created_at')->label('Reported')->dateTime(),
                        Infolists\Components\TextEntry::make('escalated_at')
                            ->label('Escalated')
                            ->dateTime()
                            ->placeholder('-')
                            ->helperText(fn (OrderIssue $record): ?string => $record->escalatedBy ? 'by '.$record->escalatedBy->name : ($record->isDispute() ? 'by a bank dispute' : null)),
                        Infolists\Components\TextEntry::make('offer')
                            ->label('Pending offer')
                            ->state(fn (OrderIssue $record): ?string => $record->hasPendingOffer() ? ucfirst((string) $record->offerLabel()) : null)
                            ->placeholder('-'),
                        Infolists\Components\TextEntry::make('resolved_at')
                            ->label('Resolved')
                            ->dateTime()
                            ->placeholder('-')
                            ->helperText(fn (OrderIssue $record): ?string => $record->resolvedBy ? 'by '.$record->resolvedBy->name : null),
                        Infolists\Components\TextEntry::make('refunded_cents')
                            ->label('Refunded through this case')
                            ->formatStateUsing(fn ($state, OrderIssue $record): string => money((int) $state, $record->order->currency)),
                    ]),
                Infolists\Components\Section::make('Bank dispute')
                    ->visible(fn (OrderIssue $record): bool => $record->isDispute())
                    ->columns(4)
                    ->schema([
                        Infolists\Components\TextEntry::make('stripe_dispute_id')->label('Dispute')->copyable(),
                        Infolists\Components\TextEntry::make('dispute_status')->label('Stripe status')->badge()->placeholder('-'),
                        Infolists\Components\TextEntry::make('dispute_reason')->label('Reason')->formatStateUsing(fn ($state) => str_replace('_', ' ', (string) $state))->placeholder('-'),
                        Infolists\Components\TextEntry::make('dispute_due_at')->label('Respond by')->dateTime()->placeholder('-'),
                        Infolists\Components\TextEntry::make('recovered_cents')
                            ->label('Recovered from the seller')
                            ->formatStateUsing(fn ($state, OrderIssue $record): string => $record->recovered_at ? money((int) $state, $record->order->currency).' on '.$record->recovered_at->format('M j, Y') : 'not recovered'),
                    ]),
                Infolists\Components\Section::make('Return')
                    ->visible(fn (OrderIssue $record): bool => filled($record->return_tracking_number))
                    ->columns(4)
                    ->schema([
                        Infolists\Components\TextEntry::make('return_carrier')->label('Carrier'),
                        Infolists\Components\TextEntry::make('return_tracking_number')->label('Tracking')->copyable(),
                        Infolists\Components\TextEntry::make('return_shipped_at')->label('Sent')->dateTime()->placeholder('-'),
                        Infolists\Components\TextEntry::make('return_received_at')->label('Received by the seller')->dateTime()->placeholder('not yet'),
                    ]),
                Infolists\Components\Section::make('Order')
                    ->columns(4)
                    ->schema([
                        Infolists\Components\TextEntry::make('order.number')
                            ->label('Order')
                            ->copyable()
                            ->weight('bold')
                            ->url(fn (OrderIssue $record): string => OrderResource::getUrl('view', ['record' => $record->order])),
                        Infolists\Components\TextEntry::make('sellerOrder.status')
                            ->label('Seller order')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => ($state instanceof \App\Enums\SellerOrderStatus ? $state : \App\Enums\SellerOrderStatus::from((string) $state))->label())
                            ->color(fn ($state): string => ($state instanceof \App\Enums\SellerOrderStatus ? $state : \App\Enums\SellerOrderStatus::from((string) $state))->color()),
                        Infolists\Components\TextEntry::make('buyer.name')
                            ->label('Buyer')
                            ->formatStateUsing(fn ($state, OrderIssue $record): string => $state.' ('.$record->buyer?->email.')')
                            ->url(fn (OrderIssue $record): ?string => $record->buyer ? UserResource::getUrl('edit', ['record' => $record->buyer]) : null),
                        Infolists\Components\TextEntry::make('seller.name')
                            ->label('Seller')
                            ->formatStateUsing(fn ($state, OrderIssue $record): string => ($record->seller?->sellerProfile?->shop_name ?: $state).' ('.$record->seller?->email.')')
                            ->url(fn (OrderIssue $record): ?string => $record->seller ? UserResource::getUrl('edit', ['record' => $record->seller]) : null),
                        Infolists\Components\RepeatableEntry::make('sellerOrder.items')
                            ->label('Items')
                            ->columnSpanFull()
                            ->columns(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('title')->hiddenLabel()->columnSpan(2),
                                Infolists\Components\TextEntry::make('is_digital')
                                    ->hiddenLabel()
                                    ->formatStateUsing(fn ($state): string => $state ? 'Digital' : 'Physical'),
                                Infolists\Components\TextEntry::make('line_total_cents')
                                    ->hiddenLabel()
                                    ->alignEnd()
                                    ->formatStateUsing(fn ($state, \App\Models\OrderItem $record): string => ($record->quantity > 1 ? $record->quantity.' x ' : '').money((int) $state, $record->order?->currency)),
                            ]),
                        Infolists\Components\TextEntry::make('paid')
                            ->label('Paid for this part')
                            ->state(fn (OrderIssue $record): string => money($record->sellerOrder->grossCents(), $record->order->currency)),
                        Infolists\Components\TextEntry::make('refunded')
                            ->label('Refunded so far')
                            ->state(fn (OrderIssue $record): string => money($record->sellerOrder->refunded_cents, $record->order->currency)),
                        Infolists\Components\TextEntry::make('payout')
                            ->label('Seller payout')
                            ->state(function (OrderIssue $record): string {
                                $so = $record->sellerOrder;
                                $outlook = app(PayoutService::class)->outlook($so);
                                $when = match ($outlook['state']) {
                                    'paid_out' => 'transferred '.$outlook['at']?->format('M j, Y'),
                                    'held' => 'on hold',
                                    'scheduled' => 'due '.$outlook['at']->format('M j, Y'),
                                    'after_ship' => 'after shipping',
                                    default => 'none',
                                };

                                return money($so->netPayoutCents(), $record->order->currency).' ('.$when.')';
                            }),
                        Infolists\Components\TextEntry::make('tracking')
                            ->label('Tracking')
                            ->state(fn (OrderIssue $record): ?string => filled($record->sellerOrder->tracking_number) ? $record->sellerOrder->carrier.' '.$record->sellerOrder->tracking_number : null)
                            ->placeholder('not shipped'),
                    ]),
                Infolists\Components\Section::make('Conversation')
                    ->description('Messages from the buyer, the seller and our team. Internal notes are only visible here.')
                    ->schema([
                        Infolists\Components\ViewEntry::make('thread')
                            ->hiddenLabel()
                            ->view('filament.order-issue-thread'),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrderIssues::route('/'),
            'view' => Pages\ViewOrderIssue::route('/{record}'),
        ];
    }

    public static function status(mixed $state): IssueStatus
    {
        return $state instanceof IssueStatus ? $state : IssueStatus::from((string) $state);
    }

    public static function reason(mixed $state): IssueReason
    {
        return $state instanceof IssueReason ? $state : IssueReason::from((string) $state);
    }

    public static function resolution(mixed $state): IssueResolution
    {
        return $state instanceof IssueResolution ? $state : IssueResolution::from((string) $state);
    }
}
