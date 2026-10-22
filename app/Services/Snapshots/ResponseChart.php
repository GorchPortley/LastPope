<?php

namespace App\Services\Snapshots;

use App\Support\BrandColors as C;
use App\Support\FrequencyResponse;
use GdImage;
use Throwable;

/**
 * The frequency response figure of the design PDF: a PNG drawn with GD from FrequencyResponse::summary() (what the
 * snapshot kept of the design's .frd files).
 *
 * Log frequency axis 20 Hz - 20 kHz with a decade (strong) and octave (light) grid, a dB axis fitted to the data, one
 * coloured line per file, the summed response as a thicker dark line, and a small legend. Drawn at twice the size and
 * scaled down, which is what makes the lines smooth. Text is DejaVu Sans, the font of the PDF.
 */
class ResponseChart
{
    /** Pixels of the finished PNG; it is shown 174 mm wide, about 200 dpi. */
    public const WIDTH = 1400;

    private const SCALE = 2;

    /** The plot never spans more than this many dB below the peak (a deep roll-off would flatten everything else). */
    private const MAX_SPAN = 60;

    private const PLOT_HEIGHT = 430;

    private const LEFT = 82;

    private const RIGHT = 76;

    private const TOP = 36;

    /** Decade lines (strong) and ISO octave centres (light); the others of a 1-2-5 series are labelled only. */
    private const DECADES = [100, 1000, 10000];

    private const OCTAVES = [31.5, 63, 125, 250, 500, 2000, 4000, 8000, 16000];

    /** Label order of the frequency axis: a label that would touch one placed before it is left out. */
    private const LABELS = [20, 100, 1000, 10000, 20000, 500, 250, 63, 2000, 4000, 8000, 31.5, 16000, 125];

    /**
     * @param  array<string, mixed>|null  $summary  FrequencyResponse::summary()
     * @return string|null PNG bytes; null when there is nothing to draw or this PHP cannot draw text
     */
    public function png(?array $summary): ?string
    {
        $series = $this->series($summary);
        $font = $this->font();

        if ($series === [] || $font === null || ! function_exists('imagettftext')) {
            return null;
        }

        try {
            return $this->draw($series, $this->sum($summary, count($series)), $font);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function dataUri(?array $summary): ?string
    {
        $png = $this->png($summary);

        return $png === null ? null : 'data:image/png;base64,'.base64_encode($png);
    }

    /** @return array<int, array{label: string, color: string, points: array<int, array{0: float, 1: float}>}> */
    private function series(?array $summary): array
    {
        $series = [];

        foreach ((array) ($summary['series'] ?? []) as $line) {
            $points = $this->points($line['points'] ?? null);

            if ($points !== []) {
                $series[] = [
                    'label' => trim((string) ($line['label'] ?? '')),
                    'color' => preg_match('/^#[0-9a-f]{6}$/i', (string) ($line['color'] ?? '')) ? $line['color'] : '#6b7280',
                    'points' => $points,
                ];
            }
        }

        return $series;
    }

    /** The summed response; with a single file it would only cover that file's line, so there is none. */
    private function sum(?array $summary, int $lines): array
    {
        return $lines > 1 ? $this->points($summary['sum'] ?? null) : [];
    }

    /** @return array<int, array{0: float, 1: float}> */
    private function points(mixed $points): array
    {
        $clean = [];

        foreach ((array) $points as $point) {
            if (is_array($point) && is_numeric($point[0] ?? null) && is_numeric($point[1] ?? null) && $point[0] > 0) {
                $clean[] = [(float) $point[0], (float) $point[1]];
            }
        }

        return $clean;
    }

    private function font(): ?string
    {
        foreach ([base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf'), storage_path('fonts/DejaVuSans.ttf')] as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{label: string, color: string, points: array}>  $series
     * @param  array<int, array{0: float, 1: float}>  $sum
     */
    private function draw(array $series, array $sum, string $font): string
    {
        $s = self::SCALE;
        $textSize = 21;
        $legend = $this->legendItems($series, $sum !== []);
        $legendRows = $this->layoutLegend($legend, $font, $textSize);

        $width = self::WIDTH;
        $plotBottom = self::TOP + self::PLOT_HEIGHT;
        $height = $plotBottom + 52 + count($legendRows) * 38 + 14;

        $im = imagecreatetruecolor($width * $s, $height * $s);
        imagealphablending($im, true);
        imagefill($im, 0, 0, $this->color($im, '#ffffff'));

        $left = self::LEFT;
        $right = $width - self::RIGHT;

        [$yMin, $yMax, $step] = $this->range([...array_column($series, 'points'), $sum]);

        $x = fn (float $f): float => ($left + (log10(max($f, FrequencyResponse::FREQ_MIN)) - log10(FrequencyResponse::FREQ_MIN)) / log10(FrequencyResponse::FREQ_MAX / FrequencyResponse::FREQ_MIN) * ($right - $left)) * $s;
        $y = fn (float $db): float => (self::TOP + (1 - ($db - $yMin) / ($yMax - $yMin)) * self::PLOT_HEIGHT) * $s;

        // grid: dB lines, octave lines, decade lines
        $grid = $this->color($im, C::gray(200));
        for ($db = $yMin; $db <= $yMax + 1e-9; $db += $step) {
            $this->line($im, $left * $s, $y($db), $right * $s, $y($db), $grid, 1.5 * $s);
        }
        foreach (self::OCTAVES as $f) {
            $this->line($im, $x($f), self::TOP * $s, $x($f), $plotBottom * $s, $this->color($im, C::gray(200)), 1.5 * $s);
        }
        foreach (self::DECADES as $f) {
            $this->line($im, $x($f), self::TOP * $s, $x($f), $plotBottom * $s, $this->color($im, C::gray(300)), 1.5 * $s);
        }
        // the frame of the frequency range, and the baseline
        $this->line($im, $left * $s, self::TOP * $s, $left * $s, $plotBottom * $s, $this->color($im, C::gray(300)), 1.5 * $s);
        $this->line($im, $right * $s, self::TOP * $s, $right * $s, $plotBottom * $s, $this->color($im, C::gray(300)), 1.5 * $s);
        $this->line($im, $left * $s, $plotBottom * $s, $right * $s, $plotBottom * $s, $this->color($im, C::gray(400)), 2 * $s);

        // the lines, clipped to the plot
        imagesetclip($im, (int) ($left * $s), (int) (self::TOP * $s), (int) ($right * $s), (int) ($plotBottom * $s));
        foreach ($series as $line) {
            $this->polyline($im, $line['points'], $x, $y, $this->color($im, $line['color']), 3.5 * $s);
        }
        if ($sum !== []) {
            $this->polyline($im, $sum, $x, $y, $this->color($im, C::brand(950)), 6 * $s);
        }
        imagesetclip($im, 0, 0, $width * $s, $height * $s);

        // labels
        $muted = $this->color($im, C::gray(600));
        for ($db = $yMin; $db <= $yMax + 1e-9; $db += $step) {
            $label = (string) (int) round($db);
            $box = $this->textBox($label, $font, $textSize * $s);
            $this->text($im, $label, $left * $s - 12 * $s - $box['width'], $y($db) + $box['height'] / 2, $font, $textSize * $s, $muted);
        }
        $this->text($im, 'dB', ($left - 12) * $s - $this->textBox('dB', $font, $textSize * $s)['width'], (self::TOP - 10) * $s, $font, $textSize * $s, $muted);

        $placed = [];
        foreach (self::LABELS as $f) {
            $label = $this->frequencyLabel($f);
            $box = $this->textBox($label, $font, $textSize * $s);
            $from = $x($f) - $box['width'] / 2;
            $to = $from + $box['width'];

            foreach ($placed as [$a, $b]) {
                if ($from < $b + 10 * $s && $to > $a - 10 * $s) {
                    continue 2;
                }
            }

            $placed[] = [$from, $to];
            $this->text($im, $label, $from, ($plotBottom + 34) * $s, $font, $textSize * $s, $muted);
        }
        $this->text($im, 'Hz', ($right + 34) * $s, ($plotBottom + 34) * $s, $font, $textSize * $s, $muted);

        // legend
        $textColor = $this->color($im, C::gray(700));
        $rowY = $plotBottom + 52;
        foreach ($legendRows as $row) {
            foreach ($row as $item) {
                $cy = ($rowY + 15) * $s;
                $this->line($im, $item['x'] * $s, $cy, ($item['x'] + 30) * $s, $cy, $this->color($im, $item['color']), $item['weight'] * $s);
                $this->text($im, $item['label'], ($item['x'] + 40) * $s, ($rowY + 23) * $s, $font, $textSize * $s, $textColor);
            }
            $rowY += 38;
        }

        $out = imagecreatetruecolor($width, $height);
        imagefill($out, 0, 0, $this->color($out, '#ffffff'));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $width, $height, $width * $s, $height * $s);

        ob_start();
        imagepng($out, null, 9);

        return (string) ob_get_clean();
    }

    /**
     * Nice bounds for the dB axis: from the lowest to the highest value (at most MAX_SPAN below the peak), on a 1, 2, 5,
     * 10 or 20 dB step that gives no more than eight intervals, with a little room above the peak.
     *
     * @param  array<int, array<int, array{0: float, 1: float}>>  $lines
     * @return array{0: float, 1: float, 2: float}
     */
    private function range(array $lines): array
    {
        $values = [];
        foreach ($lines as $points) {
            foreach ($points as $point) {
                $values[] = $point[1];
            }
        }

        $max = max($values);
        $min = max(min($values), $max - self::MAX_SPAN);
        $span = max($max - $min, 3);

        $step = 20;
        foreach ([1, 2, 5, 10, 20] as $candidate) {
            if ($span / $candidate <= 8) {
                $step = $candidate;
                break;
            }
        }

        $yMin = floor($min / $step) * $step;
        $yMax = ceil(($max + $span * 0.04) / $step) * $step;

        if ($yMax - $yMin < 2 * $step) {
            $yMax = $yMin + 2 * $step;
        }

        return [$yMin, $yMax, $step];
    }

    /** @return array<int, array{label: string, color: string, weight: float}> */
    private function legendItems(array $series, bool $withSum): array
    {
        $items = [];

        foreach ($series as $line) {
            $items[] = ['label' => $this->shorten($line['label'] !== '' ? $line['label'] : 'Response'), 'color' => $line['color'], 'weight' => 3.5];
        }

        if ($withSum) {
            $items[] = ['label' => 'Sum', 'color' => C::brand(950), 'weight' => 6.0];
        }

        return $items;
    }

    /**
     * Legend items in rows that fit the plot width.
     *
     * @param  array<int, array{label: string, color: string, weight: float}>  $items
     * @return array<int, array<int, array{label: string, color: string, weight: float, x: float}>>
     */
    private function layoutLegend(array $items, string $font, int $textSize): array
    {
        $rows = [[]];
        $x = (float) self::LEFT;
        $limit = self::WIDTH - self::RIGHT;

        foreach ($items as $item) {
            $width = 40 + $this->textBox($item['label'], $font, $textSize * self::SCALE)['width'] / self::SCALE + 34;

            if ($x + $width > $limit && $rows[array_key_last($rows)] !== []) {
                $rows[] = [];
                $x = (float) self::LEFT;
            }

            $rows[array_key_last($rows)][] = $item + ['x' => $x];
            $x += $width;
        }

        return $rows;
    }

    private function shorten(string $label): string
    {
        return mb_strlen($label) > 28 ? mb_substr($label, 0, 27).'…' : $label;
    }

    private function frequencyLabel(float $f): string
    {
        return $f >= 1000 ? rtrim(rtrim(number_format($f / 1000, 1, '.', ''), '0'), '.').'k' : rtrim(rtrim(number_format($f, 1, '.', ''), '0'), '.');
    }

    /** A line of $thickness pixels (the image is drawn at double size, so these are pixels of the big canvas). */
    private function line(GdImage $im, float $x1, float $y1, float $x2, float $y2, int $color, float $thickness): void
    {
        imagesetthickness($im, max(1, (int) round($thickness)));
        imageline($im, (int) round($x1), (int) round($y1), (int) round($x2), (int) round($y2), $color);
        imagesetthickness($im, 1);
    }

    /** A line through the points with round joints; the caller has set the clip rectangle. */
    private function polyline(GdImage $im, array $points, callable $x, callable $y, int $color, float $thickness): void
    {
        $thickness = max(1, (int) round($thickness));
        imagesetthickness($im, $thickness);

        $previous = null;
        foreach ($points as [$f, $db]) {
            $current = [$x($f), $y($db)];

            if ($previous !== null) {
                imageline($im, (int) round($previous[0]), (int) round($previous[1]), (int) round($current[0]), (int) round($current[1]), $color);
                imagefilledellipse($im, (int) round($current[0]), (int) round($current[1]), $thickness, $thickness, $color);
            }

            $previous = $current;
        }

        imagesetthickness($im, 1);
    }

    /** @return array{width: float, height: float} the size of $text at $size pixels */
    private function textBox(string $text, string $font, float $size): array
    {
        $box = imagettfbbox($size * 0.75, 0, $font, $text);

        return ['width' => (float) abs($box[2] - $box[0]), 'height' => (float) abs($box[1] - $box[7])];
    }

    /** $size is in pixels of the big canvas; GD wants points (96 dpi). */
    private function text(GdImage $im, string $text, float $x, float $baseline, string $font, float $size, int $color): void
    {
        imagettftext($im, $size * 0.75, 0, (int) round($x), (int) round($baseline), $color, $font, $text);
    }

    private function color(GdImage $im, string $hex): int
    {
        [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');

        return (int) imagecolorallocate($im, $r, $g, $b);
    }
}
