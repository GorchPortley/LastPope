<?php

namespace App\Services\Marketplace\Data;

/** Why a cart line cannot be checked out as it is. */
enum CartIssueType: string
{
    /** The product was deleted. */
    case ITEM_MISSING = 'item_missing';
    /** Inactive, seller can no longer be paid, no files to deliver... */
    case UNAVAILABLE = 'unavailable';
    case OWN_ITEM = 'own_item';
    /** A digital product the buyer already has access to. */
    case ALREADY_OWNED = 'already_owned';
    /** Not enough stock (or sold out); see CartIssue::$available. */
    case INSUFFICIENT_STOCK = 'insufficient_stock';
    /** The seller does not ship to the buyer's country. */
    case NOT_SHIPPABLE = 'not_shippable';
}
