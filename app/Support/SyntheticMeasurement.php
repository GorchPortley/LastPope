<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Physically consistent, but synthetic, loudspeaker measurements for demo data and tests.
 *
 * Everything is computed from analytic transfer functions, so magnitude and phase belong to the
 * same complex function and the Thiele/Small numbers in a driver's factory specs describe exactly
 * the curves that are written:
 *
 *  - on-axis pressure  H(f) = sensitivity x HP2(f; fs, Qts) x (high-end roll-off by driver type)
 *    where the high-end part is a low-pass plus cone breakup for woofers/mids, a gentle top-octave
 *    roll-off for tweeters;
 *  - impedance         Z(f) = Re + j*2*pi*f*Le + Res / (1 + j*Qms*(f/fs - fs/f)),  Res = Re*Qms/Qes;
 *  - design-level way  = driver pressure x Linkwitz-Riley 4th order low/high-pass at the crossover
 *    point(s), level-matched at the crossover so that the complex sum of the ways is near flat.
 *
 * FRD lines are `frequency dB phase(deg)`, ZMA lines are `frequency ohm phase(deg)`.
 * Complex numbers are [re, im] pairs.
 */
final class SyntheticMeasurement
{
    public const FREQ_MIN = 10.0;

    public const FREQ_MAX = 40000.0;

    public const POINTS_PER_DECADE = 200;

    public const TYPES = ['subwoofer', 'woofer', 'midrange', 'tweeter', 'fullrange'];

    // --- Frequencies ---------------------------------------------------------------------------

    /**
     * Log-spaced frequencies from $min to $max (inclusive), rounded to 3 decimals so that every
     * file generated with the same arguments lines up point for point.
     *
     * @return array<int, float>
     */
    public static function frequencies(
        float $min = self::FREQ_MIN,
        float $max = self::FREQ_MAX,
        int $perDecade = self::POINTS_PER_DECADE,
    ): array {
        $steps = (int) ceil(log10($max / $min) * $perDecade);
        $frequencies = [];

        for ($i = 0; $i <= $steps; $i++) {
            // the last point is exactly $max
            $frequencies[] = round(min($max, $min * 10 ** ($i / $perDecade)), 3);
        }

        return array_values(array_unique($frequencies));
    }

    // --- Driver specifications -----------------------------------------------------------------

    /**
     * Fill in a complete, internally consistent driver specification.
     *
     * Required: type (one of TYPES), fs, qes, qms, re, sd_cm2, bl. Optional: le_mh, xmax_mm, f_lp
     * (high-end roll-off corner, Hz), f_break (breakup resonance, Hz), break_gain (0 = none), f_top
     * (tweeter top-octave corner). Derived: qts, mms_g, cms_mm_n, rms, vas_l, sensitivity (dB,
     * 2.83 V / 1 m), ebp, vd_cm3.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public static function driverSpec(array $in): array
    {
        $type = $in['type'] ?? null;
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown driver type: '.var_export($type, true));
        }

        foreach (['fs', 'qes', 'qms', 're', 'sd_cm2', 'bl'] as $key) {
            if (! isset($in[$key]) || $in[$key] <= 0) {
                throw new InvalidArgumentException("Driver spec needs a positive '{$key}'.");
            }
        }

        $fs = (float) $in['fs'];
        $qes = (float) $in['qes'];
        $qms = (float) $in['qms'];
        $re = (float) $in['re'];
        $sd = (float) $in['sd_cm2'] / 10000;          // m^2
        $bl = (float) $in['bl'];
        $omega0 = 2 * M_PI * $fs;

        $qts = $qes * $qms / ($qes + $qms);
        $mms = $qes * $bl ** 2 / ($omega0 * $re);       // kg
        $cms = 1 / ($omega0 ** 2 * $mms);               // m/N
        $rms = $omega0 * $mms / $qms;                   // kg/s
        $vas = 1.18 * 343.0 ** 2 * $sd ** 2 * $cms * 1000; // litres

        // Reference efficiency (half-space) and sensitivity at 2.83 V into a nominal 1.2 x Re.
        $eta0 = (1.18 / (2 * M_PI * 343.0)) * ($bl * $sd) ** 2 / ($mms ** 2 * $re);
        $sensitivity = 112.0 + 10 * log10($eta0) + 10 * log10(8 / (1.2 * $re));

        $defaults = match ($type) {
            'subwoofer' => ['f_lp' => 600.0, 'break_gain' => 0.0, 'f_top' => 0.0],
            'woofer' => ['f_lp' => 6500.0, 'break_gain' => 0.9, 'f_top' => 0.0],
            'midrange' => ['f_lp' => 7500.0, 'break_gain' => 0.7, 'f_top' => 0.0],
            'fullrange' => ['f_lp' => 12000.0, 'break_gain' => 0.4, 'f_top' => 0.0],
            'tweeter' => ['f_lp' => 0.0, 'break_gain' => 0.0, 'f_top' => 30000.0],
        };

        $fLp = (float) ($in['f_lp'] ?? $defaults['f_lp']);

        return [
            'type' => $type,
            'fs' => $fs,
            'qes' => $qes,
            'qms' => $qms,
            'qts' => $qts,
            're' => $re,
            'le_mh' => (float) ($in['le_mh'] ?? 0.5),
            'sd_cm2' => (float) $in['sd_cm2'],
            'bl' => $bl,
            'xmax_mm' => (float) ($in['xmax_mm'] ?? 4.0),
            'mms_g' => $mms * 1000,
            'cms_mm_n' => $cms * 1000,
            'rms' => $rms,
            'vas_l' => $vas,
            'sensitivity' => round($sensitivity, 1),
            'ebp' => $fs / $qes,
            'vd_cm3' => (float) $in['sd_cm2'] * (float) ($in['xmax_mm'] ?? 4.0) / 10,
            'f_lp' => $fLp,
            'f_break' => (float) ($in['f_break'] ?? ($fLp > 0 ? $fLp * 1.8 : 0.0)),
            'break_gain' => (float) ($in['break_gain'] ?? $defaults['break_gain']),
            'f_top' => (float) ($in['f_top'] ?? $defaults['f_top']),
        ];
    }

    /**
     * The spec as the KeyValue "factory_specs" field of a Driver (string values, same keys as the
     * dashboard form).
     *
     * @param  array<string, mixed>  $spec  from driverSpec()
     * @return array<string, string>
     */
    public static function factorySpecs(array $spec): array
    {
        $mms = $spec['mms_g'];

        return [
            'Re' => self::fmt($spec['re'], 2),
            'Fs' => self::fmt($spec['fs'], 1),
            'Qms' => self::fmt($spec['qms'], 2),
            'Qes' => self::fmt($spec['qes'], 3),
            'Qts' => self::fmt($spec['qts'], 3),
            'Rms' => self::fmt($spec['rms'], 2),
            'Mms' => self::fmt($mms, 1),
            'Cms' => self::fmt($spec['cms_mm_n'], 3),
            'Vas' => self::fmt($spec['vas_l'], 1),
            'Sd' => self::fmt($spec['sd_cm2'], 1),
            'BL' => self::fmt($spec['bl'], 2),
            'Xmax' => self::fmt($spec['xmax_mm'], 1),
            'Le' => self::fmt($spec['le_mh'], 2),
            'SPL' => self::fmt($spec['sensitivity'], 1),
            'EBP' => self::fmt($spec['ebp'], 0),
            'Vd' => self::fmt($spec['vd_cm3'], 1),
            'Mmd' => self::fmt($mms * 0.94, 1),
        ];
    }

    // --- Transfer functions --------------------------------------------------------------------

    /**
     * Complex on-axis pressure response of a driver, relative to 1 (not yet scaled by sensitivity).
     *
     * @param  array<string, mixed>  $spec  from driverSpec()
     * @return array{0: float, 1: float}
     */
    public static function pressureTransfer(array $spec, float $f): array
    {
        // 2nd order high-pass at fs with Qts: s^2 / (s^2 + s*w0/Q + w0^2)
        $h = self::highPass2($f, $spec['fs'], $spec['qts']);

        switch ($spec['type']) {
            case 'tweeter':
                // gentle top-octave roll-off: 2nd order low-pass, Q 0.6
                if ($spec['f_top'] > 0) {
                    $h = self::mul($h, self::lowPass2($f, $spec['f_top'], 0.6));
                }
                break;

            default:
                if ($spec['f_lp'] > 0) {
                    $h = self::mul($h, $spec['type'] === 'subwoofer'
                        ? self::lowPass2($f, $spec['f_lp'], 0.707)
                        : self::lowPass1($f, $spec['f_lp']));
                }
                if ($spec['break_gain'] > 0 && $spec['f_break'] > 0) {
                    // cone breakup: a resonant band-pass added to the response, peak = 1 + break_gain
                    $bp = self::bandPass2($f, $spec['f_break'], 5.0);
                    $h = self::mul($h, [1 + $spec['break_gain'] * $bp[0], $spec['break_gain'] * $bp[1]]);
                }
        }

        return $h;
    }

    /**
     * On-axis pressure at $f.
     *
     * @param  array<string, mixed>  $spec
     * @return array{db: float, phase: float, complex: array{0: float, 1: float}}
     */
    public static function driverResponse(array $spec, float $f): array
    {
        $h = self::pressureTransfer($spec, $f);

        return [
            'db' => $spec['sensitivity'] + self::db($h),
            'phase' => self::phaseDeg($h),
            'complex' => $h,
        ];
    }

    /**
     * Voice-coil impedance at $f.
     *
     * @param  array<string, mixed>  $spec
     * @return array{ohm: float, phase: float, complex: array{0: float, 1: float}}
     */
    public static function impedance(array $spec, float $f): array
    {
        $fs = $spec['fs'];
        $res = $spec['re'] * $spec['qms'] / $spec['qes'];
        $omega = 2 * M_PI * $f;

        // Res / (1 + j*Qms*(f/fs - fs/f))
        $denominator = [1.0, $spec['qms'] * ($f / $fs - $fs / $f)];
        $motional = self::div([$res, 0.0], $denominator);

        $z = [$spec['re'] + $motional[0], $omega * $spec['le_mh'] / 1000 + $motional[1]];

        return [
            'ohm' => hypot($z[0], $z[1]),
            'phase' => self::phaseDeg($z),
            'complex' => $z,
        ];
    }

    /** Linkwitz-Riley 4th order (two cascaded Butterworth 2nd order sections) low-pass. */
    public static function lr4LowPass(float $f, float $fc): array
    {
        $bw = self::lowPass2($f, $fc, M_SQRT1_2);

        return self::mul($bw, $bw);
    }

    /** Linkwitz-Riley 4th order high-pass. */
    public static function lr4HighPass(float $f, float $fc): array
    {
        $bw = self::highPass2($f, $fc, M_SQRT1_2);

        return self::mul($bw, $bw);
    }

    /**
     * Complex response of one way of a design: the driver's response shaped by Linkwitz-Riley 4th
     * order crossover filters, with its level set so that the unfiltered driver reads $targetDb at
     * the crossover point (so each way is $targetDb - 6 dB there and the ways sum to $targetDb).
     *
     * $highPassAt = null for the lowest way, $lowPassAt = null for the highest way.
     *
     * @param  array<string, mixed>  $spec
     * @return array{0: float, 1: float}
     */
    public static function wayTransfer(
        array $spec,
        ?float $highPassAt,
        ?float $lowPassAt,
        float $f,
        float $targetDb = 85.0,
    ): array {
        $h = self::pressureTransfer($spec, $f);

        if ($highPassAt !== null) {
            $h = self::mul($h, self::lr4HighPass($f, $highPassAt));
        }
        if ($lowPassAt !== null) {
            $h = self::mul($h, self::lr4LowPass($f, $lowPassAt));
        }

        // Level-match: the unfiltered driver reads $targetDb at the reference frequency (the
        // crossover point; mid-band for a band-pass way), so neighbouring ways meet at equal levels.
        $reference = match (true) {
            $highPassAt !== null && $lowPassAt !== null => sqrt($highPassAt * $lowPassAt),
            $highPassAt !== null => $highPassAt,
            $lowPassAt !== null => $lowPassAt,
            default => sqrt($spec['fs'] * max($spec['f_lp'], $spec['f_top'], $spec['fs'] * 4)),
        };
        $gain = 10 ** (($targetDb - self::db(self::pressureTransfer($spec, $reference))) / 20);

        return [$h[0] * $gain, $h[1] * $gain];
    }

    /**
     * Largest deviation (dB) from $targetDb of the complex sum of a low-pass way and a high-pass way
     * around the crossover point $xo, looked at from $xo / $span to $xo * $span.
     *
     * @param  array<string, mixed>  $lowSpec  driver of the lower way (low-passed at $xo)
     * @param  array<string, mixed>  $highSpec  driver of the upper way (high-passed at $xo)
     */
    public static function crossoverDeviation(array $lowSpec, array $highSpec, float $xo, float $targetDb = 85.0, float $span = 1.5): float
    {
        $worst = 0.0;

        for ($i = 0; $i <= 24; $i++) {
            $f = $xo / $span * ($span * $span) ** ($i / 24);
            $a = self::wayTransfer($lowSpec, null, $xo, $f, $targetDb);
            $b = self::wayTransfer($highSpec, $xo, null, $f, $targetDb);
            $worst = max($worst, abs(self::db([$a[0] + $b[0], $a[1] + $b[1]]) - $targetDb));
        }

        return $worst;
    }

    /**
     * Pick the crossover frequency in [$min, $max] (rounded to a "nice" number) where the two drivers
     * sum flattest. Drivers whose own phase differs a lot at the crossover cannot sum flat anywhere;
     * this returns the best compromise.
     *
     * @param  array<string, mixed>  $lowSpec
     * @param  array<string, mixed>  $highSpec
     */
    public static function bestCrossover(array $lowSpec, array $highSpec, float $min, float $max): float
    {
        $best = $min;
        $bestDeviation = INF;

        for ($xo = $min; $xo <= $max; $xo *= 2 ** (1 / 12)) {
            $deviation = self::crossoverDeviation($lowSpec, $highSpec, $xo);
            if ($deviation < $bestDeviation) {
                $bestDeviation = $deviation;
                $best = $xo;
            }
        }

        return $best < 1000 ? round($best / 10) * 10 : round($best / 50) * 50;
    }

    // --- File content --------------------------------------------------------------------------

    /**
     * FRD file of the driver's on-axis response: `freq dB phase`.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<int, float>|null  $frequencies
     */
    public static function driverFrd(array $spec, ?array $frequencies = null, string $title = 'synthetic driver'): string
    {
        $lines = ["* {$title} - synthetic on-axis response (SDLabs demo data)", '* freq[Hz] SPL[dB] phase[deg]'];

        foreach ($frequencies ?? self::frequencies() as $f) {
            $r = self::driverResponse($spec, $f);
            $lines[] = self::line($f, $r['db'], $r['phase'], 3, 2);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * ZMA file of the driver's impedance: `freq ohm phase`.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<int, float>|null  $frequencies
     */
    public static function driverZma(array $spec, ?array $frequencies = null, string $title = 'synthetic driver'): string
    {
        $lines = ["* {$title} - synthetic impedance (SDLabs demo data)", '* freq[Hz] Z[ohm] phase[deg]'];

        foreach ($frequencies ?? self::frequencies() as $f) {
            $z = self::impedance($spec, $f);
            $lines[] = self::line($f, $z['ohm'], $z['phase'], 3, 2);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * FRD file of one way of a design (driver x LR4 filters, level matched).
     *
     * @param  array<string, mixed>  $spec
     * @param  array<int, float>|null  $frequencies
     */
    public static function wayFrd(
        array $spec,
        ?float $highPassAt,
        ?float $lowPassAt,
        string $title,
        ?array $frequencies = null,
        float $targetDb = 85.0,
    ): string {
        $lines = ["* {$title} - synthetic design response (SDLabs demo data)", '* freq[Hz] SPL[dB] phase[deg]'];

        foreach ($frequencies ?? self::frequencies() as $f) {
            $h = self::wayTransfer($spec, $highPassAt, $lowPassAt, $f, $targetDb);
            $lines[] = self::line($f, self::db($h), self::phaseDeg($h), 3, 2);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Parse FRD/ZMA text into [[freq, value, phase], ...] (comment lines start with * or #).
     *
     * @return array<int, array{0: float, 1: float, 2: float}>
     */
    public static function parse(string $content): array
    {
        $rows = [];

        foreach (preg_split('/\R/', trim($content)) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '*' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 3) {
                $rows[] = [(float) $parts[0], (float) $parts[1], (float) $parts[2]];
            }
        }

        return $rows;
    }

    // --- Complex helpers -----------------------------------------------------------------------

    /** Magnitude of a complex number in dB. */
    public static function db(array $z): float
    {
        return 20 * log10(max(hypot($z[0], $z[1]), 1e-12));
    }

    /** Phase in degrees, wrapped to (-180, 180]. */
    public static function phaseDeg(array $z): float
    {
        $deg = rad2deg(atan2($z[1], $z[0]));

        return $deg <= -180.0 ? $deg + 360.0 : $deg;
    }

    /** @return array{0: float, 1: float} */
    public static function mul(array $a, array $b): array
    {
        return [$a[0] * $b[0] - $a[1] * $b[1], $a[0] * $b[1] + $a[1] * $b[0]];
    }

    /** @return array{0: float, 1: float} */
    public static function div(array $a, array $b): array
    {
        $d = $b[0] ** 2 + $b[1] ** 2;

        return [($a[0] * $b[0] + $a[1] * $b[1]) / $d, ($a[1] * $b[0] - $a[0] * $b[1]) / $d];
    }

    /** s^2 / (s^2 + s*w0/Q + w0^2) at s = j*w, written with x = f/f0. */
    private static function highPass2(float $f, float $f0, float $q): array
    {
        $x = $f / $f0;

        return self::div([-$x * $x, 0.0], [1 - $x * $x, $x / $q]);
    }

    /** w0^2 / (s^2 + s*w0/Q + w0^2) */
    private static function lowPass2(float $f, float $f0, float $q): array
    {
        $x = $f / $f0;

        return self::div([1.0, 0.0], [1 - $x * $x, $x / $q]);
    }

    /** (s*w0/Q) / (s^2 + s*w0/Q + w0^2): unity at f0. */
    private static function bandPass2(float $f, float $f0, float $q): array
    {
        $x = $f / $f0;

        return self::div([0.0, $x / $q], [1 - $x * $x, $x / $q]);
    }

    /** w0 / (s + w0) */
    private static function lowPass1(float $f, float $f0): array
    {
        return self::div([1.0, 0.0], [1.0, $f / $f0]);
    }

    private static function line(float $f, float $value, float $phase, int $valueDecimals, int $phaseDecimals): string
    {
        return sprintf('%.3f %.'.$valueDecimals.'f %.'.$phaseDecimals.'f', $f, $value, $phase);
    }

    private static function fmt(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', '');
    }
}
