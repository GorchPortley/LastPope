<?php

namespace App\Filament\Resources\ListingResource\Pages;

use App\Enums\ListingChannel;
use App\Filament\Resources\ListingResource;
use App\Models\Listing;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListListings extends ListRecords
{
    protected static string $resource = ListingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('createStore')
                ->label('New store listing')
                ->icon('phosphor-storefront')
                ->url(ListingResource::getUrl('create', ['channel' => ListingChannel::STORE->value])),
            Actions\Action::make('createMarketplace')
                ->label('New marketplace listing')
                ->color('gray')
                ->url(ListingResource::getUrl('create', ['channel' => ListingChannel::MARKETPLACE->value])),
        ];
    }

    /**
     * Store (SDLabs' own new drivers, sold from the driver pages) and marketplace (what members sell) are
     * two different jobs, so they get a tab each.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'store' => Tab::make('Store')
                ->icon('phosphor-storefront')
                ->badge(fn (): int => Listing::query()->store()->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('channel', ListingChannel::STORE->value)),
            'marketplace' => Tab::make('Marketplace')
                ->icon('phosphor-users-three')
                ->badge(fn (): int => Listing::query()->marketplace()->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('channel', ListingChannel::MARKETPLACE->value)),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }
}
