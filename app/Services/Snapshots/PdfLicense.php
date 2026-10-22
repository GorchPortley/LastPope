<?php

namespace App\Services\Snapshots;

use App\Enums\EntitlementSource;
use App\Models\Design;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Who a downloaded design PDF is licensed to, printed in its footer: the downloader's name and username (never the
 * e-mail address), the download date and where the copy comes from (an order number, a free download, the designer's
 * own copy or an admin's copy).
 */
final class PdfLicense
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $username,
        public readonly CarbonInterface $date,
        public readonly string $label,
        public readonly string $url,
        public readonly int $userId = 0,
    ) {
    }

    /** The licence of $user's copy of $design as of $date (default now). */
    public static function for(User $user, Design $design, ?CarbonInterface $date = null): self
    {
        return new self(
            name: Str::limit(trim((string) $user->name) !== '' ? trim((string) $user->name) : (string) ($user->username ?: 'SDLabs member'), 60),
            username: filled($user->username) ? (string) $user->username : null,
            date: $date ?? now(),
            label: self::labelFor($user, $design),
            url: rtrim((string) config('app.url'), '/'),
            userId: (int) $user->getKey(),
        );
    }

    /** "Order SDL-7KQ2M9", "Free download", "Designer copy", "Admin copy" (or "Complimentary copy" for a grant by an admin). */
    public static function labelFor(User $user, Design $design): string
    {
        if ($design->getSeller()?->is($user)) {
            return 'Designer copy';
        }

        if ($user->isPlatformSeller()) {
            return 'Admin copy';
        }

        $entitlement = $user->entitlementFor($design);
        $number = $entitlement?->orderItem?->order?->number;

        if (filled($number)) {
            return 'Order '.$number;
        }

        return match ($entitlement?->source) {
            EntitlementSource::OWNER => 'Designer copy',
            EntitlementSource::ADMIN_GRANT => 'Complimentary copy',
            EntitlementSource::PURCHASE => 'Purchased copy',
            default => 'Free download',
        };
    }

    /**
     * The parts of the footer line, in order, as plain text (the view escapes them).
     *
     * @return array<int, string>
     */
    public function segments(): array
    {
        return array_values(array_filter([
            'Licensed to '.$this->name.($this->username !== null ? ' (@'.$this->username.')' : ''),
            'downloaded '.$this->date->format('M j, Y'),
            $this->label,
            $this->url,
        ], fn (string $part) => $part !== ''));
    }

    public function line(): string
    {
        return implode(' · ', $this->segments());
    }

    /** Changes whenever anything printed in the footer changes: part of the personalised PDF's cache key. */
    public function fingerprint(): string
    {
        return substr(sha1($this->line()), 0, 12);
    }
}
