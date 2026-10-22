<?php

namespace App\Enums;

use App\Models\SellerOrder;

/** Why a buyer reports a problem with one seller's part of an order. */
enum IssueReason: string
{
    case NOT_SHIPPED = 'not_shipped';
    case NOT_RECEIVED = 'not_received';
    case NOT_AS_DESCRIBED = 'not_as_described';
    case DAMAGED = 'damaged';
    case WRONG_ITEM = 'wrong_item';
    case DOWNLOAD_PROBLEM = 'download_problem';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NOT_SHIPPED => 'The seller has not shipped it',
            self::NOT_RECEIVED => 'It never arrived',
            self::NOT_AS_DESCRIBED => 'Not as described',
            self::DAMAGED => 'Arrived damaged',
            self::WRONG_ITEM => 'Wrong item',
            self::DOWNLOAD_PROBLEM => 'Problem with the download',
            self::OTHER => 'Something else',
        };
    }

    /** Short form for tables and badges. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::NOT_SHIPPED => 'Not shipped',
            self::NOT_RECEIVED => 'Not received',
            self::NOT_AS_DESCRIBED => 'Not as described',
            self::DAMAGED => 'Damaged',
            self::WRONG_ITEM => 'Wrong item',
            self::DOWNLOAD_PROBLEM => 'Download problem',
            self::OTHER => 'Other',
        };
    }

    /**
     * The reasons that make sense for this seller order: digital items cannot be "not received",
     * a parcel that was not shipped yet cannot be "damaged", a shipped one is no longer "not shipped".
     *
     * @return array<int, self>
     */
    public static function forSellerOrder(SellerOrder $sellerOrder): array
    {
        $sellerOrder->loadMissing('items');

        if (! $sellerOrder->hasPhysicalItems()) {
            return [self::DOWNLOAD_PROBLEM, self::NOT_AS_DESCRIBED, self::OTHER];
        }

        if ($sellerOrder->shipped_at === null) {
            return [self::NOT_SHIPPED, self::NOT_AS_DESCRIBED, self::OTHER];
        }

        return [self::NOT_RECEIVED, self::NOT_AS_DESCRIBED, self::DAMAGED, self::WRONG_ITEM, self::OTHER];
    }

    /** @return array<string, string> value => label of the reasons that apply to $sellerOrder, for selects */
    public static function optionsFor(SellerOrder $sellerOrder): array
    {
        return collect(self::forSellerOrder($sellerOrder))->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
