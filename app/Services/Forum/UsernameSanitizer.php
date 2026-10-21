<?php

namespace App\Services\Forum;

use Illuminate\Support\Str;

/**
 * Turns an app username into something Flarum accepts.
 *
 * Flarum user names: 3-30 characters, letters, digits, "_" and "-" only, and they must not be
 * all digits (a numeric name is ambiguous with a user id in /u/{id} links). Names that are
 * reserved (the forum admin, "admin", ...) or already taken get a numeric suffix.
 */
class UsernameSanitizer
{
    public const MIN_LENGTH = 3;

    public const MAX_LENGTH = 30;

    /** @param array<int, string> $reserved lower-case names that members may not use */
    public function __construct(private readonly array $reserved = [])
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('forum.reserved_usernames', []));
    }

    /** The cleaned base name, before any collision suffix. */
    public function base(string $username, ?string $fallback = null): string
    {
        // "José Müller" -> "Jose Muller", then anything outside the allowed set becomes "_"
        $name = Str::ascii($username);
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $name) ?? '';
        $name = trim(preg_replace('/_{2,}/', '_', $name) ?? '', '_-');

        if ($name === '' && $fallback !== null && $fallback !== $username) {
            return $this->base($fallback);
        }

        if ($name === '') {
            $name = 'member';
        }

        if (ctype_digit($name)) {
            $name = 'u'.$name;
        }

        if (strlen($name) < self::MIN_LENGTH) {
            $name = str_pad($name, self::MIN_LENGTH, '_');
        }

        return substr($name, 0, self::MAX_LENGTH);
    }

    public function isReserved(string $name): bool
    {
        return in_array(strtolower($name), $this->reserved, true);
    }

    /**
     * Candidate names to try, in order: the base name (unless reserved), then base_2, base_3, ...
     * The suffix is appended after truncating the base so the result stays within 30 characters.
     *
     * @return array<int, string>
     */
    public function candidates(string $username, ?string $fallback = null, int $count = 5): array
    {
        $base = $this->base($username, $fallback);
        $names = [];

        if (! $this->isReserved($base)) {
            $names[] = $base;
        }

        for ($i = 2; count($names) < $count; $i++) {
            $suffix = '_'.$i;
            $candidate = substr($base, 0, self::MAX_LENGTH - strlen($suffix)).$suffix;

            if (! $this->isReserved($candidate)) {
                $names[] = $candidate;
            }
        }

        return $names;
    }
}
