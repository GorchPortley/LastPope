<?php

namespace App\Support;

/**
 * The default (not season-themed) brand palette as hex values, for the places that cannot use the site's CSS
 * variables: the design PDF (dompdf) and the e-mail theme (resources/views/vendor/mail/html/themes/sdlabs.css, which
 * has to be plain CSS). The source of truth is :root in resources/themes/blank/assets/css/app.css (RGB channels);
 * tests/Feature/BrandColorsTest.php fails when this class, that file or the mail theme drift apart.
 */
final class BrandColors
{
    /** Tailwind purple: header, footer, buttons, links. */
    public const BRAND = [
        50 => '#faf5ff', 100 => '#f3e8ff', 200 => '#e9d5ff', 300 => '#d8b4fe', 400 => '#c084fc', 500 => '#a855f7',
        600 => '#9333ea', 700 => '#7e22ce', 800 => '#6b21a8', 900 => '#581c87', 950 => '#3b0764',
    ];

    /** Tailwind amber: highlights on the dark header and footer. */
    public const ACCENT = [
        50 => '#fffbeb', 100 => '#fef3c7', 200 => '#fde68a', 300 => '#fcd34d', 400 => '#fbbf24', 500 => '#f59e0b',
        600 => '#d97706', 700 => '#b45309', 800 => '#92400e', 900 => '#78350f', 950 => '#451a03',
    ];

    /** Tailwind blue: link hover, focus rings. */
    public const LINK = [
        50 => '#eff6ff', 100 => '#dbeafe', 200 => '#bfdbfe', 300 => '#93c5fd', 400 => '#60a5fa', 500 => '#3b82f6',
        600 => '#2563eb', 700 => '#1d4ed8', 800 => '#1e40af', 900 => '#1e3a8a', 950 => '#172554',
    ];

    /** Tailwind gray, for text and rules. */
    public const GRAY = [
        50 => '#f9fafb', 100 => '#f3f4f6', 200 => '#e5e7eb', 300 => '#d1d5db', 400 => '#9ca3af', 500 => '#6b7280',
        600 => '#4b5563', 700 => '#374151', 800 => '#1f2937', 900 => '#111827',
    ];

    public static function brand(int $shade): string
    {
        return self::BRAND[$shade];
    }

    public static function accent(int $shade): string
    {
        return self::ACCENT[$shade];
    }

    public static function link(int $shade): string
    {
        return self::LINK[$shade];
    }

    public static function gray(int $shade): string
    {
        return self::GRAY[$shade];
    }

    /** "147 51 234" (a CSS variable value in app.css) to "#9333ea". */
    public static function hexFromChannels(string $channels): string
    {
        $parts = preg_split('/\s+/', trim($channels)) ?: [];

        return sprintf('#%02x%02x%02x', (int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0));
    }
}
