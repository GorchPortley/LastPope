<?php

namespace App\Filament\Resources\EntitlementResource\Pages;

use App\Enums\EntitlementSource;
use App\Filament\Resources\EntitlementResource;
use App\Models\Design;
use App\Models\User;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListEntitlements extends ListRecords
{
    protected static string $resource = EntitlementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('grant')
                ->label('Grant access')
                ->icon('heroicon-m-plus')
                ->modalHeading('Grant access to a design')
                ->modalDescription('Gives the user the download rights of a design without an order.')
                ->form([
                    Select::make('user_id')
                        ->label('User')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => User::query()
                            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                            ->limit(30)->get()->mapWithKeys(fn (User $user) => [$user->id => $user->name.' ('.$user->email.')'])->all())
                        ->getOptionLabelUsing(fn ($value): ?string => User::find($value)?->name)
                        ->required(),
                    Select::make('item_id')
                        ->label('Design')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Design::query()->where('name', 'like', "%{$search}%")->limit(30)->get()
                            ->mapWithKeys(fn (Design $design) => [$design->id => $design->name])->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Design::find($value)?->purchasableTitle())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $user = User::findOrFail($data['user_id']);
                    $item = Design::findOrFail($data['item_id']);

                    $user->grantEntitlement($item, EntitlementSource::ADMIN_GRANT);

                    Notification::make()->success()->title('Access granted')->body($user->name.' can now download '.$item->purchasableTitle().'.')->send();
                }),
        ];
    }
}
