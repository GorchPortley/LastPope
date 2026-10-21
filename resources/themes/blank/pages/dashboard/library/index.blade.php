<?php

use App\Models\Design;
use App\Models\Entitlement;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Volt\Component;
use function Laravel\Folio\{middleware, name};

middleware('auth');
name('dashboard.library');

new class extends Component implements HasForms, Tables\Contracts\HasTable {
    use InteractsWithForms, Tables\Concerns\InteractsWithTable;

    public ?array $data = [];

    public function table(Table $table): Table
    {
        return $table
            ->query(Entitlement::query()
                ->where('user_id', auth()->id())
                ->whereNull('revoked_at')
                ->with('entitleable'))
            ->heading('Library')
            ->description('Designs and data packs you have purchased or collected')
            ->defaultPaginationPageOption(5)
            ->recordUrl(fn (Entitlement $record): string => $record->entitleable instanceof Design
                ? route('design', ['id' => $record->entitleable_id])
                : route('driver', ['id' => $record->entitleable_id])
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->state(fn (Entitlement $record): string => $record->entitleable?->purchasableTitle() ?? 'Removed')
                    ->description(fn (Entitlement $record): string => $record->entitleable?->tag ?? '#'),
                TextColumn::make('type')
                    ->label('Type')
                    ->state(fn (Entitlement $record): string => $record->entitleable instanceof Design ? 'Design' : 'Driver data'),
                TextColumn::make('category')
                    ->label('Category')
                    ->state(fn (Entitlement $record): ?string => $record->entitleable?->category),
                TextColumn::make('seller')
                    ->label('Designer')
                    ->state(fn (Entitlement $record): ?string => $record->entitleable?->getSeller()?->name),
            ]);
    }
}
?>

<x-layouts.app>
    @volt('dashboard.design-library')
    {{ $this->table }}
    @endvolt
</x-layouts.app>
