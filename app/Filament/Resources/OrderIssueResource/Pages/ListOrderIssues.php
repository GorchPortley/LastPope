<?php

namespace App\Filament\Resources\OrderIssueResource\Pages;

use App\Enums\IssueStatus;
use App\Filament\Resources\OrderIssueResource;
use App\Models\OrderIssue;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListOrderIssues extends ListRecords
{
    protected static string $resource = OrderIssueResource::class;

    /** One tab per stage of the queue; the escalated cases (waiting for an admin) come first. */
    public function getTabs(): array
    {
        $count = fn (IssueStatus ...$statuses): int => OrderIssue::query()->status(...$statuses)->count();

        return [
            'escalated' => Tab::make('Needs a decision')
                ->badge($count(IssueStatus::ESCALATED))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->status(IssueStatus::ESCALATED)),
            'negotiating' => Tab::make('Buyer and seller talking')
                ->badge($count(IssueStatus::OPEN, IssueStatus::OFFER_PENDING, IssueStatus::AWAITING_RETURN))
                ->modifyQueryUsing(fn (Builder $query) => $query->status(IssueStatus::OPEN, IssueStatus::OFFER_PENDING, IssueStatus::AWAITING_RETURN)),
            'resolved' => Tab::make('Resolved')
                ->modifyQueryUsing(fn (Builder $query) => $query->status(IssueStatus::RESOLVED)),
            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'escalated';
    }
}
