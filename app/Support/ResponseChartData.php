<?php

namespace App\Support;

/**
 * What the measured-response chart on the design page needs, from the data FrequencyResponse::forFiles() returns
 * (that class stays the only place that parses and adds up the measurements): one line per file in the colours of
 * docs/STYLE.md, the sum, and the two numbers shown next to the chart.
 *
 *   midband  the average level of the sum from 300 Hz to 3 kHz
 *   f3       the lowest frequency at which the sum is at or above midband - 3 dB (the "-3 dB low end")
 *
 * Both are worked out from the full sum; only the points handed to the browser are thinned (one per 1/60 octave).
 */
final class ResponseChartData
{
    /** amber, teal, pink, blue; the fifth line starts again */
    public const COLORS = ['#d97706', '#0f766e', '#be185d', '#2563eb'];

    private const POINTS_PER_OCTAVE = 60;

    /**
     * `$summed` false is for several measurements of one thing (a driver on and off axis): the lines are not added up,
     * `sum` stays empty and midband / -3 dB are worked out from the first file.
     *
     * @param  array{chartData?: array, summedResponse?: array}  $response  FrequencyResponse::forFiles()
     * @return array{series: array<int, array{label: string, color: string, points: array}>, sum: array, summed: bool, midband: ?float, f3: ?float}|null
     */
    public static function from(array $response, bool $summed = true): ?array
    {
        $sum = array_map(fn (array $p) => [(float) $p['x'], (float) $p['y']], $response['summedResponse'] ?? []);

        if ($sum === []) {
            return null;
        }

        $series = [];
        foreach (array_values($response['chartData'] ?? []) as $i => $line) {
            $series[] = [
                'label' => (string) $line['label'],
                'color' => self::COLORS[$i % count(self::COLORS)],
                'points' => self::thin(array_map(fn (array $p) => [(float) $p['x'], (float) $p['y']], $line['data'])),
            ];
        }

        $reference = $summed
            ? $sum
            : array_map(fn (array $p) => [(float) $p['x'], (float) $p['y']], array_values($response['chartData'] ?? [])[0]['data'] ?? []);
        $midband = self::midband($reference);

        return [
            'series' => $series,
            'sum' => $summed ? self::thin($sum) : [],
            'summed' => $summed,
            'midband' => $midband,
            'f3' => $midband === null ? null : self::lowEnd($reference, $midband),
        ];
    }

    /** @param  array<int, array{0: float, 1: float}>  $sum */
    public static function midband(array $sum): ?float
    {
        $levels = array_column(array_filter($sum, fn (array $p) => $p[0] >= 300 && $p[0] <= 3000), 1);

        return $levels === [] ? null : array_sum($levels) / count($levels);
    }

    /** @param  array<int, array{0: float, 1: float}>  $sum */
    public static function lowEnd(array $sum, float $midband): ?float
    {
        usort($sum, fn (array $a, array $b) => $a[0] <=> $b[0]);

        foreach ($sum as [$f, $level]) {
            if ($f >= FrequencyResponse::FREQ_MIN && $level >= $midband - 3) {
                return $f;
            }
        }

        return null;
    }

    /** 812 Hz, 1.6 kHz, 12 kHz. */
    public static function hz(float $f): string
    {
        return $f >= 1000 ? number_format($f / 1000, $f >= 10000 ? 0 : 1).' kHz' : round($f).' Hz';
    }

    /**
     * One point per 1/60 octave (the mean of the points in it); coarser data is kept as is.
     *
     * @param  array<int, array{0: float, 1: float}>  $points
     * @return array<int, array{0: float, 1: float}>
     */
    private static function thin(array $points): array
    {
        usort($points, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $bins = [];
        foreach ($points as [$f, $y]) {
            $bin = (int) floor(log($f / FrequencyResponse::FREQ_MIN, 2) * self::POINTS_PER_OCTAVE);
            $bins[$bin] ??= ['f' => 0.0, 'y' => 0.0, 'n' => 0];
            $bins[$bin]['f'] += $f;
            $bins[$bin]['y'] += $y;
            $bins[$bin]['n']++;
        }

        return array_values(array_map(
            fn (array $b) => [round($b['f'] / $b['n'], 1), round($b['y'] / $b['n'], 2)],
            $bins,
        ));
    }
}
