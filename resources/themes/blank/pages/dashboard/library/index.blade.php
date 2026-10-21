<?php

use App\Enums\EntitlementSource;
use App\Models\Design;
use App\Models\Entitlement;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Volt\Component;
use function Laravel\Folio\{middleware, name};

middleware('auth');
name('dashboard.library');

new class extends Component implements HasForms, Tables\Contracts\HasTable {
    use InteractsWithForms, InteractsWithTable;

    public function table(Table $table): Table
    {
        $source = fn ($state): EntitlementSource => $state instanceof EntitlementSource ? $state : EntitlementSource::from((string) $state);
        $latest = fn (Entitlement $record) => $record->entitleable?->snapshots->sortByDesc('id')->first();

        return $table
            ->query(
                Entitlement::query()
                    ->where('user_id', auth()->id())
                    ->whereNull('revoked_at')
                    ->whereHasMorph('entitleable', [Design::class])
                    ->with(['entitleable.snapshots', 'entitleable.designer', 'orderItem.order'])
            )
            ->heading('Library')
            ->description('Designs you bought, collected for free, or own. Download the newest snapshot, or any earlier version.')
            ->defaultSort('granted_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->columns([
                ImageColumn::make('cover')
                    ->label('')
                    ->state(function (Entitlement $record): ?string {
                        $image = $record->entitleable?->card_image;

                        return is_array($image) ? ($image[0] ?? null) : $image;
                    })
                    ->disk('public')
                    ->square()
                    ->size(48)
                    ->visibleFrom('md'),
                TextColumn::make('design')
                    ->label('Design')
                    ->state(fn (Entitlement $record): string => (string) $record->entitleable?->purchasableTitle())
                    ->description(fn (Entitlement $record): string => 'By '.($record->entitleable?->getSeller()?->name ?? 'unknown'))
                    ->url(fn (Entitlement $record): ?string => $record->entitleable ? route('design', ['id' => $record->entitleable->getKey()]) : null)
                    ->weight('medium')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHasMorph(
                        'entitleable', [Design::class], fn (Builder $designs) => $designs->where('name', 'like', '%'.$search.'%')
                    )),
                TextColumn::make('source')
                    ->label('How you got it')
                    ->visibleFrom('md')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $source($state)->label())
                    ->color(fn ($state): string => match ($source($state)) {
                        EntitlementSource::PURCHASE => 'success',
                        EntitlementSource::FREE => 'info',
                        default => 'gray',
                    })
                    ->description(function (Entitlement $record): ?string {
                        $order = $record->orderItem?->order;

                        return $order ? 'Order '.$order->number : null;
                    }),
                TextColumn::make('granted_at')
                    ->label('Since')
                    ->state(fn (Entitlement $record) => $record->granted_at ?? $record->created_at)
                    ->date('M j, Y')
                    ->visibleFrom('md')
                    ->sortable(),
                TextColumn::make('latest_version')
                    ->label('Latest files')
                    ->state(fn (Entitlement $record): string => $latest($record) ? 'Version '.$latest($record)->version : 'No files yet')
                    ->description(fn (Entitlement $record): ?string => $latest($record)?->file_size ? \Illuminate\Support\Number::fileSize($latest($record)->file_size) : null)
                    ->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('source')->label('How you got it')->options(EntitlementSource::options()),
            ])
            ->actions([
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->visible(fn (Entitlement $record): bool => $latest($record) !== null)
                    ->url(fn (Entitlement $record): ?string => $latest($record) ? route('design-snapshots.download', $latest($record)) : null),
                Action::make('versions')
                    ->label('All versions')
                    ->icon('heroicon-m-queue-list')
                    ->color('gray')
                    ->modalHeading(fn (Entitlement $record): string => $record->entitleable?->purchasableTitle().' - all versions')
                    ->modalContent(fn (Entitlement $record) => view('marketplace.library-versions', ['design' => $record->entitleable]))
                    ->modalWidth('lg')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->emptyStateIcon('phosphor-books')
            ->emptyStateHeading('Your library is empty')
            ->emptyStateDescription('Free designs you collect, and everything you buy, will show up here.')
            ->emptyStateActions([
                Action::make('designs')->label('Browse designs')->url(route('designs')),
                Action::make('drivers')->label('Browse drivers')->url(route('drivers'))->color('gray'),
            ]);
    }
}; ?>

<x-layouts.app>
    @volt('dashboard.design-library')
    <div>
        {{ $this->table }}
    </div>
    @endvolt
</x-layouts.app>
