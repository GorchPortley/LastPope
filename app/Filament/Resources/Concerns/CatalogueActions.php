<?php

namespace App\Filament\Resources\Concerns;

use App\Jobs\BuildDesignSnapshot;
use App\Jobs\BuildDriverSnapshot;
use App\Models\Design;
use App\Models\Driver;
use App\Models\User;
use App\Services\Catalogue\OwnershipTransfer;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin actions shared by the design and driver resources, for the table rows and the edit page header:
 *
 *     CatalogueActions::transferOwnership(TableAction::class)
 *     CatalogueActions::snapshot(PageAction::class)
 */
class CatalogueActions
{
    /** @param  class-string<TableAction|PageAction>  $class */
    public static function transferOwnership(string $class): TableAction|PageAction
    {
        return $class::make('transferOwnership')
            ->label('Transfer ownership')
            ->icon('phosphor-arrows-left-right')
            ->color('gray')
            ->modalDescription('Past sales and pending payouts stay with the current owner. A new owner with the manufacturer role marks it official.')
            ->form(fn (Design|Driver $record): array => [
                Forms\Components\Placeholder::make('current')
                    ->label('Current owner')
                    ->content(fn (): string => $record->getAttribute('user_id') ? (User::find($record->user_id)?->name ?? 'Deleted user #'.$record->user_id) : 'Nobody'),
                Forms\Components\Select::make('user_id')
                    ->label('New owner')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => static::userOptions($search))
                    ->getOptionLabelUsing(fn ($value): ?string => User::find($value)?->name)
                    ->different(fn () => (string) $record->user_id)
                    ->required(),
                Forms\Components\Toggle::make('keep_copy')
                    ->label('The current owner keeps access to the files')
                    ->helperText('Grants them a free copy (paid designs only; free designs are open to everyone anyway).')
                    ->default(true)
                    ->visible($record instanceof Design),
            ])
            ->action(function (array $data, Design|Driver $record): void {
                $to = User::findOrFail($data['user_id']);
                app(OwnershipTransfer::class)->transfer($record, $to, auth()->user(), (bool) ($data['keep_copy'] ?? false));

                Notification::make()->success()->title('Ownership transferred')->body('Now owned by '.$to->name.'.')->send();
            });
    }

    /**
     * @param  class-string<TableAction|PageAction>  $class
     * @param  (\Closure(): Design|Driver)|null  $item  for a header action without a record (relation managers)
     */
    public static function snapshot(string $class, ?\Closure $item = null): TableAction|PageAction
    {
        return $class::make('snapshot')
            ->label('New snapshot')
            ->icon('heroicon-m-camera')
            ->color('gray')
            ->form([
                Forms\Components\TextInput::make('version')
                    ->label('Snapshot version')
                    ->default(fn (): string => 'v'.now()->format('YmdHis'))
                    ->required()
                    ->maxLength(100),
            ])
            ->action(function (array $data, ?Model $record) use ($item): void {
                $record = $item ? $item() : $record;
                $record instanceof Design
                    ? BuildDesignSnapshot::dispatch($record, $data['version'], auth()->user())
                    : BuildDriverSnapshot::dispatch($record, $data['version'], auth()->user());

                Notification::make()->success()->title('Snapshot queued')->body('Building '.$data['version'].'. You will get a notification when it is ready.')->send();
            });
    }

    /** @param  class-string<TableAction|PageAction>  $class */
    public static function viewOnSite(string $class): TableAction|PageAction
    {
        return $class::make('viewOnSite')
            ->label('View on site')
            ->icon('heroicon-m-arrow-top-right-on-square')
            ->color('gray')
            ->url(fn (Design|Driver $record): string => $record instanceof Design ? route('design', ['id' => $record->id]) : route('driver', ['id' => $record->id]))
            ->openUrlInNewTab();
    }

    /** User select options "Name (@username, e-mail)" for a search term. */
    public static function userOptions(string $search): array
    {
        return User::query()
            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('username', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->name.' (@'.$user->username.', '.$user->email.')'])
            ->all();
    }

    /** The fixed Thiele/Small parameter list used by factory specs and measured specs. */
    public static function specKeys(): array
    {
        return ['Re', 'Fs', 'Qms', 'Qes', 'Qts', 'Rms', 'Mms', 'Cms', 'Vas', 'Sd', 'BL', 'Xmax', 'Le', 'SPL', 'EBP', 'Vd', 'Mmd'];
    }
}
