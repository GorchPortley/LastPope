<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManagerStatic as Image;

/**
 * Where avatars live and how they are served.
 *
 * nginx sends every image with "Cache-Control: max-age=31536000, immutable" (serversideup's performance.conf), so a
 * browser never asks for the same URL twice. An avatar therefore gets a new file name on every upload
 * (avatars/{username}-{random}.jpg) instead of overwriting avatars/{username}.png, and nobody needs the old
 * "?timestamp" cache buster, which made every page load download the picture again.
 *
 * Users without a picture (empty, Wave's demo/default.png, Chatify's avatar.png) get the site's default avatar,
 * public/images/avatar-default.png, which ships with the app instead of depending on the demo files in storage.
 */
class Avatars
{
    /** Uploaded avatars are stored as SIZE x SIZE JPEGs: big enough for the profile page, small for the header. */
    public const SIZE = 400;

    public const DEFAULT_IMAGE = 'images/avatar-default.png';

    /** Values that mean "no picture of their own". */
    private const PLACEHOLDERS = ['demo/default.png', 'avatar.png'];

    public static function isDefault(?string $avatar): bool
    {
        return blank($avatar) || in_array($avatar, self::PLACEHOLDERS, true);
    }

    public static function url(?string $avatar): string
    {
        if (self::isDefault($avatar)) {
            return self::defaultUrl();
        }

        return Str::startsWith($avatar, ['http://', 'https://']) ? $avatar : Storage::disk('public')->url($avatar);
    }

    public static function defaultUrl(): string
    {
        return VersionedAsset::url(self::DEFAULT_IMAGE);
    }

    /** The bytes of the user's avatar (their own, or the default when theirs is missing), for the forum upload. */
    public static function contents(User $user): string
    {
        if (! self::isDefault($user->avatar) && Storage::disk('public')->exists($user->avatar)) {
            return Storage::disk('public')->get($user->avatar);
        }

        return file_get_contents(public_path(self::DEFAULT_IMAGE));
    }

    /**
     * Save an uploaded picture (anything Intervention reads: a file, a path, a data: URI) as the user's new avatar:
     * square, SIZE pixels, JPEG, under a new name. The previous upload is deleted.
     */
    public static function store(User $user, mixed $source): string
    {
        $path = 'avatars/'.Str::slug((string) $user->username ?: 'user-'.$user->getKey()).'-'.Str::lower(Str::random(8)).'.jpg';
        $image = Image::make($source)->orientate()->fit(self::SIZE, self::SIZE);

        // JPEG has no transparency: put transparent pictures on white instead of black.
        $canvas = Image::canvas(self::SIZE, self::SIZE, '#ffffff')->insert($image);
        Storage::disk('public')->put($path, (string) $canvas->encode('jpg', 85));

        $previous = $user->avatar;
        $user->forceFill(['avatar' => $path])->save();

        if (! self::isDefault($previous) && Str::startsWith($previous, 'avatars/') && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return $path;
    }
}
