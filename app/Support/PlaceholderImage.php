<?php

namespace App\Support;

use GdImage;
use RuntimeException;

/**
 * Simple generated images for demo data (no downloads, no fonts): a cabinet with driver circles
 * for designs, a cone for drivers, a photo-ish product tile for listings. Returned as JPEG bytes.
 * Everything is derived from the integer seed, so the same seed always gives the same picture.
 */
final class PlaceholderImage
{
    private const SCALE = 2; // drawn at 2x, then scaled down for smooth edges

    /** @param int $ways 1 = single driver, 2 = tweeter over woofer, 3+ = tweeter, mid, woofers */
    public static function design(int $seed, int $ways = 2, bool $subwoofer = false, int $width = 800, int $height = 600): string
    {
        [$img, $w, $h] = self::canvas($width, $height);
        $hue = self::rand($seed, 1) * 360;
        self::background($img, $w, $h, self::hsl($hue, 0.18, 0.20), self::hsl($hue + 20, 0.22, 0.34));

        $wood = self::hsl(20 + self::rand($seed, 2) * 25, 0.45, 0.30 + self::rand($seed, 3) * 0.12);
        $woodDark = self::shade($wood, -0.45);
        $woodLight = self::shade($wood, 0.18);

        // floor shadow
        self::ellipse($img, (int) ($w / 2), (int) ($h * 0.90), (int) ($w * 0.42), (int) ($h * 0.05), imagecolorallocatealpha($img, 0, 0, 0, 90));

        if ($subwoofer) {
            $cw = (int) ($w * 0.46);
            $ch = (int) ($h * 0.62);
        } else {
            $cw = (int) ($w * 0.34);
            $ch = (int) ($h * (0.58 + 0.06 * min($ways, 3)));
        }
        $x = (int) (($w - $cw) / 2);
        $y = (int) ($h * 0.90 - $ch);

        // cabinet body, side depth, front baffle
        $depth = (int) ($w * 0.045);
        self::roundedRect($img, $x + $depth, $y - (int) ($depth * 0.6), $x + $cw + $depth, $y + $ch - (int) ($depth * 0.6), 14, $woodDark);
        self::roundedRect($img, $x, $y, $x + $cw, $y + $ch, 14, $wood);
        self::roundedRect($img, $x + 10, $y + 10, $x + $cw - 10, $y + $ch - 10, 10, $woodLight);
        self::roundedRect($img, $x + 16, $y + 16, $x + $cw - 16, $y + $ch - 16, 8, $wood);

        $cx = (int) ($x + $cw / 2);
        $drivers = match (true) {
            $subwoofer => [[$cx, (int) ($y + $ch / 2), (int) ($cw * 0.38), 'cone']],
            $ways <= 1 => [[$cx, (int) ($y + $ch / 2), (int) ($cw * 0.32), 'cone']],
            $ways === 2 => [
                [$cx, (int) ($y + $ch * 0.24), (int) ($cw * 0.14), 'dome'],
                [$cx, (int) ($y + $ch * 0.64), (int) ($cw * 0.33), 'cone'],
            ],
            default => [
                [$cx, (int) ($y + $ch * 0.16), (int) ($cw * 0.12), 'dome'],
                [$cx, (int) ($y + $ch * 0.38), (int) ($cw * 0.21), 'cone'],
                [$cx, (int) ($y + $ch * 0.74), (int) ($cw * 0.30), 'cone'],
            ],
        };

        foreach ($drivers as $i => [$dx, $dy, $r, $kind]) {
            $kind === 'dome' ? self::dome($img, $dx, $dy, $r, $seed + $i) : self::cone($img, $dx, $dy, $r, $seed + $i);
        }

        return self::jpeg($img, $width, $height);
    }

    public static function driver(int $seed, string $kind = 'woofer', int $size = 600): string
    {
        [$img, $w, $h] = self::canvas($size, $size);
        $hue = 200 + self::rand($seed, 1) * 100;
        self::background($img, $w, $h, self::hsl($hue, 0.10, 0.88), self::hsl($hue, 0.12, 0.72));
        self::ellipse($img, (int) ($w / 2), (int) ($h * 0.86), (int) ($w * 0.30), (int) ($h * 0.035), imagecolorallocatealpha($img, 0, 0, 0, 100));

        $cx = (int) ($w / 2);
        $cy = (int) ($h * 0.47);

        if ($kind === 'tweeter') {
            // faceplate square with a dome
            $half = (int) ($w * 0.30);
            self::roundedRect($img, $cx - $half, $cy - $half, $cx + $half, $cy + $half, 18, self::hsl(215, 0.10, 0.22));
            self::dome($img, $cx, $cy, (int) ($w * 0.22), $seed);
        } else {
            $outer = (int) ($w * ($kind === 'subwoofer' ? 0.40 : 0.36));
            // basket: ring with mounting ears
            self::ellipse($img, $cx, $cy, $outer + 14, $outer + 14, self::hsl(215, 0.08, 0.18));
            foreach ([45, 135, 225, 315] as $angle) {
                $ex = $cx + (int) (cos(deg2rad($angle)) * ($outer + 6));
                $ey = $cy + (int) (sin(deg2rad($angle)) * ($outer + 6));
                self::ellipse($img, $ex, $ey, 16, 16, self::hsl(215, 0.08, 0.30));
            }
            self::cone($img, $cx, $cy, $outer, $seed);
        }

        return self::jpeg($img, $size, $size);
    }

    /**
     * Product-photo tile for marketplace listings.
     *
     * @param  bool  $used  adds scuffs/wear marks and a warmer, dimmer light
     */
    public static function listing(int $seed, string $kind = 'woofer', bool $used = false, int $width = 800, int $height = 600): string
    {
        [$img, $w, $h] = self::canvas($width, $height);
        $tone = $used ? 0.55 : 0.72;
        self::background($img, $w, $h, self::hsl(35, 0.08, $tone + 0.10), self::hsl(30, 0.10, $tone - 0.10));

        // table edge / surface
        $surface = self::hsl(28, 0.30, $used ? 0.28 : 0.36);
        imagefilledrectangle($img, 0, (int) ($h * 0.72), $w, $h, $surface);
        imagefilledrectangle($img, 0, (int) ($h * 0.72), $w, (int) ($h * 0.725), self::shade($surface, -0.35));

        $count = ($seed % 4 === 0) ? 2 : 1; // some listings are pairs
        $radius = (int) ($h * ($kind === 'tweeter' ? 0.15 : ($count === 2 ? 0.20 : 0.27)));
        $positions = $count === 2 ? [(int) ($w * 0.33), (int) ($w * 0.67)] : [(int) ($w / 2)];

        foreach ($positions as $i => $px) {
            $py = (int) ($h * 0.66 - ($i * 8));
            self::ellipse($img, $px, (int) ($h * 0.78), (int) ($radius * 1.05), (int) ($radius * 0.14), imagecolorallocatealpha($img, 0, 0, 0, 85));
            if ($kind === 'tweeter') {
                self::roundedRect($img, $px - $radius, $py - $radius, $px + $radius, $py + $radius, 14, self::hsl(215, 0.10, 0.20));
                self::dome($img, $px, $py, (int) ($radius * 0.72), $seed + $i);
            } else {
                self::ellipse($img, $px, $py, $radius + 10, $radius + 10, self::hsl(215, 0.08, 0.16));
                self::cone($img, $px, $py, $radius, $seed + $i);
            }

            if ($used) {
                // scuffs and wear
                for ($s = 0; $s < 9; $s++) {
                    $angle = self::rand($seed + $i, 20 + $s) * 2 * M_PI;
                    $dist = self::rand($seed + $i, 40 + $s) * $radius;
                    $sx = $px + (int) (cos($angle) * $dist);
                    $sy = $py + (int) (sin($angle) * $dist);
                    imagesetthickness($img, 2);
                    imageline($img, $sx, $sy, $sx + (int) (12 * cos($angle + 1)), $sy + (int) (12 * sin($angle + 1)), imagecolorallocatealpha($img, 235, 235, 235, 60));
                }
            }
        }

        return self::jpeg($img, $width, $height);
    }

    /** A wide promotional strip: colour gradient, soft circles and a couple of drivers. */
    public static function banner(int $seed, int $width = 1200, int $height = 240): string
    {
        [$img, $w, $h] = self::canvas($width, $height);
        $hue = self::rand($seed, 1) * 360;
        self::background($img, $w, $h, self::hsl($hue, 0.55, 0.28), self::hsl($hue + 40, 0.60, 0.42));

        for ($i = 0; $i < 6; $i++) {
            $radius = (int) ($h * (0.25 + self::rand($seed, 10 + $i) * 0.7));
            self::ellipse($img, (int) (self::rand($seed, 30 + $i) * $w), (int) (self::rand($seed, 50 + $i) * $h), $radius, $radius, imagecolorallocatealpha($img, 255, 255, 255, 112));
        }

        self::cone($img, (int) ($w * 0.80), (int) ($h * 0.50), (int) ($h * 0.40), $seed);
        self::dome($img, (int) ($w * 0.93), (int) ($h * 0.50), (int) ($h * 0.22), $seed + 1);

        return self::jpeg($img, $width, $height);
    }

    // --- Drawing primitives --------------------------------------------------------------------

    /** @return array{0: GdImage, 1: int, 2: int} */
    private static function canvas(int $width, int $height): array
    {
        $w = $width * self::SCALE;
        $h = $height * self::SCALE;
        $img = imagecreatetruecolor($w, $h);
        if ($img === false) {
            throw new RuntimeException('GD could not create an image.');
        }
        imagealphablending($img, true);

        return [$img, $w, $h];
    }

    private static function jpeg(GdImage $img, int $width, int $height): string
    {
        $out = imagecreatetruecolor($width, $height);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $width, $height, imagesx($img), imagesy($img));

        ob_start();
        imagejpeg($out, null, 84);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }

    private static function background(GdImage $img, int $w, int $h, int $top, int $bottom): void
    {
        [$r1, $g1, $b1] = [($top >> 16) & 255, ($top >> 8) & 255, $top & 255];
        [$r2, $g2, $b2] = [($bottom >> 16) & 255, ($bottom >> 8) & 255, $bottom & 255];

        for ($y = 0; $y < $h; $y++) {
            $t = $y / max(1, $h - 1);
            $color = imagecolorallocate($img, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t));
            imageline($img, 0, $y, $w, $y, $color);
        }
    }

    private static function ellipse(GdImage $img, int $cx, int $cy, int $rx, int $ry, int $color): void
    {
        imagefilledellipse($img, $cx, $cy, $rx * 2, $ry * 2, $color);
    }

    private static function roundedRect(GdImage $img, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        foreach ([[$x1 + $radius, $y1 + $radius], [$x2 - $radius, $y1 + $radius], [$x1 + $radius, $y2 - $radius], [$x2 - $radius, $y2 - $radius]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, $radius * 2, $radius * 2, $color);
        }
    }

    /** A cone driver seen from the front: surround, cone shading, dust cap, highlight. */
    private static function cone(GdImage $img, int $cx, int $cy, int $r, int $seed): void
    {
        $tint = self::rand($seed, 5) * 40;

        self::ellipse($img, $cx, $cy, $r, $r, self::hsl(215, 0.05, 0.10));                  // gap / basket
        self::ellipse($img, $cx, $cy, (int) ($r * 0.94), (int) ($r * 0.94), self::hsl(0, 0.0, 0.16)); // surround
        self::ellipse($img, $cx, $cy, (int) ($r * 0.80), (int) ($r * 0.80), self::hsl(0, 0.0, 0.07));

        // cone: concentric rings from dark rim to a lighter centre
        $steps = 14;
        for ($i = 0; $i < $steps; $i++) {
            $radius = (int) ($r * (0.76 - 0.62 * $i / $steps));
            self::ellipse($img, $cx, $cy, $radius, $radius, self::hsl(210 + $tint, 0.10, 0.17 + 0.13 * $i / $steps));
        }

        // dust cap and highlight
        $cap = (int) ($r * 0.20);
        self::ellipse($img, $cx, $cy, $cap, $cap, self::hsl(210 + $tint, 0.08, 0.36));
        self::ellipse($img, $cx - (int) ($cap * 0.25), $cy - (int) ($cap * 0.30), (int) ($cap * 0.45), (int) ($cap * 0.45), imagecolorallocatealpha($img, 255, 255, 255, 95));

        // surround highlight arc
        imagesetthickness($img, 5);
        imagearc($img, $cx, $cy, (int) ($r * 1.78), (int) ($r * 1.78), 200, 290, imagecolorallocatealpha($img, 255, 255, 255, 90));
    }

    /** A dome tweeter: faceplate ring and a shiny dome. */
    private static function dome(GdImage $img, int $cx, int $cy, int $r, int $seed): void
    {
        $metal = self::rand($seed, 7) > 0.5;

        self::ellipse($img, $cx, $cy, $r, $r, self::hsl(215, 0.06, 0.12));
        self::ellipse($img, $cx, $cy, (int) ($r * 0.88), (int) ($r * 0.88), self::hsl(215, 0.06, 0.22));
        self::ellipse($img, $cx, $cy, (int) ($r * 0.66), (int) ($r * 0.66), $metal ? self::hsl(45, 0.20, 0.55) : self::hsl(30, 0.15, 0.78));
        self::ellipse($img, $cx - (int) ($r * 0.12), $cy - (int) ($r * 0.14), (int) ($r * 0.30), (int) ($r * 0.30), imagecolorallocatealpha($img, 255, 255, 255, 85));
    }

    // --- Colour --------------------------------------------------------------------------------

    /** Deterministic pseudo-random float in [0, 1) from a seed and an index. */
    private static function rand(int $seed, int $i): float
    {
        $v = sin($seed * 12.9898 + $i * 78.233) * 43758.5453;

        return $v - floor($v);
    }

    /** HSL (hue in degrees, s and l in 0..1) to a packed 0xRRGGBB integer, which is a valid colour in truecolor GD images. */
    private static function hsl(float $h, float $s, float $l): int
    {
        $h = fmod(fmod($h, 360) + 360, 360) / 360;
        $l = max(0.0, min(1.0, $l));
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $channel = function (float $t) use ($p, $q): float {
            $t = ($t + 1) - floor($t + 1);

            return match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };
        };

        return ((int) round($channel($h + 1 / 3) * 255) << 16) | ((int) round($channel($h) * 255) << 8) | (int) round($channel($h - 1 / 3) * 255);
    }

    private static function shade(int $rgb, float $amount): int
    {
        $apply = fn (int $c): int => (int) max(0, min(255, round($amount >= 0 ? $c + (255 - $c) * $amount : $c * (1 + $amount))));

        return ($apply(($rgb >> 16) & 255) << 16) | ($apply(($rgb >> 8) & 255) << 8) | $apply($rgb & 255);
    }
}
