<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * The measured responses (.frd files: "frequency dB phase" per line) of a design: parsed, drawn as one line per file,
 * and added up as complex numbers into the summed response.
 *
 * One implementation for everything that shows the response: the chart on the design page (Livewire
 * FrequencyResponseViewer) and the preview in the design PDF (DesignSnapshotBuilder, ResponseChart). The files are
 * source files on the private disk and are only read server-side.
 */
final class FrequencyResponse
{
    public const FREQ_MIN = 20;

    public const FREQ_MAX = 20000;

    public const AMP_MIN = 0;

    public const AMP_MAX = 120;

    /** One colour per file, in file order (the eighth repeats the first, as it always did on the site). */
    public const COLORS = [
        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
        '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF',
    ];

    /**
     * Everything the response viewer shows for the files at $paths (private disk): the amplitude line of each file, the
     * summed response and the phase lines. Files that are missing or hold no usable points are skipped.
     *
     * @param  array<int, mixed>  $paths
     * @return array{chartData: array, summedResponse: array, phaseData: array}
     */
    public static function forFiles(array $paths): array
    {
        $disk = Storage::disk('private');
        $datasets = [];
        $colorIndex = 0;

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '' || ! $disk->exists($path)) {
                continue;
            }

            $points = self::parse((string) ($disk->get($path) ?? ''));

            if ($points !== []) {
                $datasets[] = [
                    'data' => $points,
                    'label' => pathinfo($path, PATHINFO_FILENAME),
                    'color' => self::COLORS[$colorIndex % count(self::COLORS)],
                ];
                $colorIndex++;
            }
        }

        $amplitude = self::amplitude($datasets);

        return [
            'chartData' => $amplitude['chartData'],
            'summedResponse' => $amplitude['summedResponse'],
            'phaseData' => self::phase($datasets),
        ];
    }

    /**
     * The response as the design PDF keeps it: the lines of the files (label, colour, points) and their sum, each
     * reduced to one point per 1/$perOctave octave so a snapshot stays small. Null when there is nothing to draw.
     *
     * @param  array<int, mixed>  $paths
     * @return array{series: array<int, array{label: string, color: string, points: array<int, array{0: float, 1: float}>}>, sum: array<int, array{0: float, 1: float}>}|null
     */
    public static function summary(array $paths, int $perOctave = 48): ?array
    {
        $response = self::forFiles($paths);

        $series = collect($response['chartData'])
            ->map(fn (array $line) => [
                'label' => (string) $line['label'],
                'color' => (string) $line['borderColor'],
                'points' => self::reduce(array_map(fn (array $p) => [$p['x'], $p['y']], $line['data']), $perOctave),
            ])
            ->filter(fn (array $line) => $line['points'] !== [])
            ->values()
            ->all();

        if ($series === []) {
            return null;
        }

        return [
            'series' => $series,
            'sum' => self::reduce(array_map(fn (array $p) => [$p['x'], $p['y']], $response['summedResponse']), $perOctave),
        ];
    }

    /**
     * FRD text to points. Lines starting with * or # are comments; frequencies outside 20 Hz - 20 kHz and levels
     * outside 0 - 120 dB are dropped.
     *
     * @return array<int, array{frequency: float, amplitude: float, phase: float}>
     */
    public static function parse(string $content): array
    {
        $lines = array_filter(
            explode("\n", trim($content)),
            fn ($line) => ! empty($line) && $line[0] !== '*' && $line[0] !== '#'
        );

        $data = [];
        foreach ($lines as $line) {
            $values = preg_split('/\s+/', trim($line));
            if (count($values) < 3) {
                continue;
            }

            $freq = (float) $values[0];
            $amp = (float) $values[1];

            if ($freq < self::FREQ_MIN || $freq > self::FREQ_MAX || $amp < self::AMP_MIN || $amp > self::AMP_MAX) {
                continue;
            }

            $data[] = ['frequency' => $freq, 'amplitude' => $amp, 'phase' => (float) $values[2]];
        }

        return $data;
    }

    /**
     * One amplitude line per file, and the summed response: at every frequency the files are added as complex numbers
     * (level and phase), with the low end smoothed.
     *
     * @param  array<int, array{data: array, label: string, color: string}>  $datasets
     * @return array{chartData: array, summedResponse: array}
     */
    private static function amplitude(array $datasets): array
    {
        $chartData = [];
        $summed = [];

        foreach ($datasets as $dataset) {
            $chartData[] = [
                'label' => $dataset['label'],
                'data' => array_map(fn ($point) => [
                    'x' => $point['frequency'],
                    'y' => $point['amplitude'],
                ], $dataset['data']),
                'borderColor' => $dataset['color'],
                'fill' => false,
            ];

            foreach ($dataset['data'] as $point) {
                $amplitude = pow(10, $point['amplitude'] / 20.0);
                $phase = deg2rad($point['phase']);

                $real = $amplitude * cos($phase);
                $imag = $amplitude * sin($phase);

                $key = (string) $point['frequency'];

                if (! isset($summed[$key])) {
                    $summed[$key] = ['real' => $real, 'imag' => $imag, 'count' => 0];
                } else {
                    $summed[$key]['real'] += $real;
                    $summed[$key]['imag'] += $imag;
                    $summed[$key]['count']++;
                }
            }
        }

        $summedResponse = [];
        if (! empty($summed)) {
            ksort($summed, SORT_NUMERIC);

            $points = [];
            foreach ($summed as $freq => $complex) {
                $magnitude = sqrt(pow($complex['real'], 2) + pow($complex['imag'], 2));
                $points[] = ['x' => (float) $freq, 'y' => 20 * log10(max($magnitude, 1e-20))];
            }

            $summedResponse = self::smoothLowFrequencies($points);
        }

        return ['chartData' => $chartData, 'summedResponse' => $summedResponse];
    }

    /** @return array<int, array{x: float, y: float}> */
    private static function smoothLowFrequencies(array $data, int $lowFreqThreshold = 60, int $windowSize = 33): array
    {
        usort($data, fn ($a, $b) => $a['x'] <=> $b['x']);

        $smoothed = [];
        foreach ($data as $index => $point) {
            // a moving average below the threshold, the measured values above it
            if ($point['x'] < $lowFreqThreshold) {
                $windowStart = max(0, $index - floor($windowSize / 2));
                $windowEnd = min(count($data) - 1, $index + floor($windowSize / 2));

                $window = array_slice($data, $windowStart, $windowEnd - $windowStart + 1);

                $smoothed[] = ['x' => $point['x'], 'y' => array_sum(array_column($window, 'y')) / count($window)];
            } else {
                $smoothed[] = $point;
            }
        }

        return $smoothed;
    }

    /** @return array<int, array{label: string, data: array, borderColor: string, fill: bool}> */
    private static function phase(array $datasets): array
    {
        return array_map(fn (array $dataset) => [
            'label' => $dataset['label'].' Phase',
            'data' => array_map(fn ($point) => [
                'x' => $point['frequency'],
                'y' => self::normalizePhase($point['phase']),
            ], $dataset['data']),
            'borderColor' => $dataset['color'],
            'fill' => false,
        ], $datasets);
    }

    private static function normalizePhase(float $phase): float
    {
        while ($phase > 180) {
            $phase -= 360;
        }
        while ($phase < -180) {
            $phase += 360;
        }

        return $phase;
    }

    /**
     * One point per 1/$perOctave octave (the mean of the points in it), in frequency order; coarser data is kept as is.
     *
     * @param  array<int, array{0: float, 1: float}>  $points
     * @return array<int, array{0: float, 1: float}>
     */
    private static function reduce(array $points, int $perOctave): array
    {
        usort($points, fn ($a, $b) => $a[0] <=> $b[0]);

        $bins = [];
        foreach ($points as [$f, $y]) {
            $bin = (int) floor(log($f / self::FREQ_MIN, 2) * $perOctave);

            $bins[$bin] ??= ['f' => 0.0, 'y' => 0.0, 'n' => 0];
            $bins[$bin]['f'] += $f;
            $bins[$bin]['y'] += $y;
            $bins[$bin]['n']++;
        }

        return array_values(array_map(
            fn (array $b) => [round($b['f'] / $b['n'], $b['f'] / $b['n'] < 1000 ? 2 : 1), round($b['y'] / $b['n'], 2)],
            $bins,
        ));
    }
}
