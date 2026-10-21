<?php

namespace App\Filament\Resources\SellerProfileResource\Pages;

use App\Filament\Resources\SellerProfileResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditSellerProfile extends EditRecord
{
    protected static string $resource = SellerProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshFromStripe')
                ->label('Refresh from Stripe')
                ->icon('heroicon-m-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => filled($this->record->stripe_account_id))
                ->action(function (): void {
                    if (SellerProfileResource::refreshFromStripe($this->record)) {
                        $this->refreshFormData(['charges_enabled', 'payouts_enabled', 'details_submitted']);
                    }
                }),
        ];
    }
}
