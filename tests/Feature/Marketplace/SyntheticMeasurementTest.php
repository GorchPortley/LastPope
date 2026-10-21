<?php

use App\Http\Livewire\FrequencyResponseViewer;
use App\Models\Design;
use App\Support\SyntheticMeasurement as S;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function woofer(array $overrides = []): array
{
    return S::driverSpec($overrides + ['type' => 'woofer', 'fs' => 38, 'qes' => 0.45, 'qms' => 3.2, 're' => 5.6, 'sd_cm2' => 133, 'bl' => 7.4, 'le_mh' => 0.7, 'xmax_mm' => 5.5]);
}

function tweeter(array $overrides = []): array
{
    return S::driverSpec($overrides + ['type' => 'tweeter', 'fs' => 700, 'qes' => 0.8, 'qms' => 2.4, 're' => 4.7, 'sd_cm2' => 5.7, 'bl' => 2.7, 'le_mh' => 0.04, 'xmax_mm' => 0.5]);
}

it('produces log-spaced frequencies from 10 Hz to 40 kHz', function () {
    $f = S::frequencies();

    expect($f[0])->toBe(10.0)
        ->and(end($f))->toBe(40000.0)
        ->and(count($f))->toBeGreaterThan(600)->toBeLessThan(900);

    // constant ratio between neighbours (log spacing), within the rounding to 3 decimals; the last step is cut short at 40 kHz
    $ratios = collect($f)->slice(0, -1)->sliding(2)->map(fn ($pair) => $pair->last() / $pair->first())->all();
    expect(max($ratios) - min($ratios))->toBeLessThan(0.001)
        ->and(S::frequencies(10, 40000, 48) === S::frequencies(10, 40000, 48))->toBeTrue();
});

it('derives the T/S parameters of a driver from one consistent set', function () {
    $spec = woofer();
    $specs = S::factorySpecs($spec);

    expect(array_keys($specs))->toBe(['Re', 'Fs', 'Qms', 'Qes', 'Qts', 'Rms', 'Mms', 'Cms', 'Vas', 'Sd', 'BL', 'Xmax', 'Le', 'SPL', 'EBP', 'Vd', 'Mmd'])
        ->and((float) $specs['Qts'])->toEqualWithDelta(1 / (1 / (float) $specs['Qes'] + 1 / (float) $specs['Qms']), 0.002)
        ->and((float) $specs['Fs'])->toBe(38.0)
        ->and((float) $specs['Re'])->toBe(5.6)
        // Mms and Cms resonate at Fs: 1 / (2 pi sqrt(Mms Cms)) = Fs
        ->and(1 / (2 * M_PI * sqrt(((float) $specs['Mms'] / 1000) * ((float) $specs['Cms'] / 1000))))->toEqualWithDelta(38.0, 0.5)
        ->and((float) $specs['SPL'])->toBeGreaterThan(80)->toBeLessThan(100);
});

it('draws the pressure response as sensitivity x second-order high-pass at fs with Qts', function () {
    $spec = woofer();

    // at fs the high-pass is down by 20 log10(Qts) dB; the woofer's own roll-off is far away
    $atFs = S::driverResponse($spec, $spec['fs']);
    expect($atFs['db'] - $spec['sensitivity'])->toEqualWithDelta(20 * log10($spec['qts']), 0.1);

    // 12 dB per octave below fs, flat above
    $low = S::driverResponse($spec, $spec['fs'] / 4)['db'];
    $lower = S::driverResponse($spec, $spec['fs'] / 8)['db'];
    expect($low - $lower)->toBeGreaterThan(10)->toBeLessThan(12.5);

    $mid = S::driverResponse($spec, 600)['db'];
    expect($mid)->toEqualWithDelta($spec['sensitivity'], 1.0);
});

it('rolls woofers off at the top and gives them a breakup resonance, tweeters a gentle top-octave roll-off', function () {
    $w = woofer();
    $t = tweeter();

    $passband = S::driverResponse($w, 500)['db'];
    $breakup = collect(S::frequencies(2000, 30000))->map(fn ($f) => S::driverResponse($w, $f)['db'])->max();
    $top = S::driverResponse($w, 40000)['db'];

    expect(S::driverResponse($w, 6500)['db'])->toBeLessThan($passband - 2)
        ->and($top)->toBeLessThan($passband - 15)
        ->and($breakup)->toBeGreaterThan(S::driverResponse($w, 4000)['db']); // a peak above the low-pass knee

    // tweeter: flat through the audible band, only ~ -2 dB at 20 kHz, ~ -9 dB at 40 kHz
    $ref = S::driverResponse($t, 5000)['db'];
    expect(S::driverResponse($t, 20000)['db'])->toBeGreaterThan($ref - 4)
        ->and(S::driverResponse($t, 40000)['db'])->toBeLessThan($ref - 4)
        ->and(S::driverResponse($t, 100)['db'])->toBeLessThan($ref - 20);        // high-passed at fs
});

it('writes phase as the argument of the same complex response, wrapped to +-180 degrees', function () {
    $spec = woofer();

    foreach ([10, 22, 38, 80, 400, 3000, 9000, 25000] as $f) {
        $r = S::driverResponse($spec, $f);
        expect(S::phaseDeg($r['complex']))->toBe($r['phase'])
            ->and($r['phase'])->toBeGreaterThan(-180.0001)->toBeLessThanOrEqual(180.0)
            ->and($r['db'])->toEqualWithDelta($spec['sensitivity'] + 20 * log10(hypot(...$r['complex'])), 1e-9);
    }

    // a high-pass leads: positive phase well below fs, tending to 0 well above it
    expect(S::driverResponse($spec, 10)['phase'])->toBeGreaterThan(90)
        ->and(abs(S::driverResponse($spec, 400)['phase']))->toBeLessThan(15);
});

it('writes FRD and ZMA files the viewers can read', function () {
    $spec = woofer();
    $frd = S::driverFrd($spec, null, 'Test driver');
    $zma = S::driverZma($spec);

    $lines = array_values(array_filter(explode("\n", $frd), fn ($l) => $l !== '' && $l[0] !== '*'));
    expect($lines)->toHaveCount(count(S::frequencies()))
        ->and($frd)->toStartWith('* Test driver')
        ->and(preg_split('/\s+/', $lines[0]))->toHaveCount(3)
        ->and($lines[0])->toMatch('/^10\.000 \S+ \S+$/');

    $rows = S::parse($frd);
    expect($rows)->toHaveCount(count(S::frequencies()))
        ->and($rows[0][0])->toBe(10.0)
        ->and(S::parse($zma)[0][0])->toBe(10.0);
});

it('has an impedance peak at fs of Re + Res and a rising inductive tail', function () {
    $spec = woofer();
    $res = $spec['re'] * $spec['qms'] / $spec['qes'];

    $peak = collect(S::frequencies(0.5 * $spec['fs'], 2 * $spec['fs']))
        ->map(fn ($f) => ['f' => $f, 'z' => S::impedance($spec, $f)['ohm']])
        ->sortByDesc('z')->first();

    expect($peak['f'])->toEqualWithDelta($spec['fs'], $spec['fs'] * 0.03)
        ->and($peak['z'])->toEqualWithDelta($spec['re'] + $res, ($spec['re'] + $res) * 0.02)
        // between the resonance and the inductance rise the impedance is about Re
        ->and(collect(S::frequencies(5 * $spec['fs'], 10 * $spec['fs'], 20))->map(fn ($f) => S::impedance($spec, $f)['ohm'])->min())
            ->toEqualWithDelta($spec['re'], 0.5)
        ->and(S::impedance($spec, 40000)['ohm'])->toBeGreaterThan(S::impedance($spec, 5000)['ohm'])
        ->and(S::impedance($spec, 40000)['ohm'])->toBeGreaterThan($spec['re'] * 10)
        // inductive below the peak, capacitive above it (until the voice-coil inductance takes over)
        ->and(S::impedance($spec, $spec['fs'] * 0.5)['phase'])->toBeGreaterThan(0)
        ->and(S::impedance($spec, $spec['fs'] * 2)['phase'])->toBeLessThan(0)
        ->and(abs(S::impedance($spec, $spec['fs'])['phase']))->toBeLessThan(6);
});

it('builds 4th order Linkwitz-Riley filters: -6 dB at the corner and an all-pass sum', function () {
    foreach ([120, 800, 2500] as $fc) {
        expect(S::db(S::lr4LowPass($fc, $fc)))->toEqualWithDelta(-6.02, 0.01)
            ->and(S::db(S::lr4HighPass($fc, $fc)))->toEqualWithDelta(-6.02, 0.01);

        foreach (S::frequencies($fc / 10, $fc * 10, 20) as $f) {
            $lp = S::lr4LowPass($f, $fc);
            $hp = S::lr4HighPass($f, $fc);

            expect(S::db([$lp[0] + $hp[0], $lp[1] + $hp[1]]))->toEqualWithDelta(0.0, 1e-9);
        }

        // 24 dB per octave
        expect(S::db(S::lr4LowPass($fc * 4, $fc)) - S::db(S::lr4LowPass($fc * 8, $fc)))->toBeGreaterThan(20)->toBeLessThan(25);
    }
});

it('sums a low-pass and a high-pass way flat (within 1 dB) across the crossover region', function () {
    $low = woofer();
    $high = tweeter();
    $xo = 2000.0;

    foreach (S::frequencies($xo / 1.5, $xo * 1.5, 60) as $f) {
        $a = S::wayTransfer($low, null, $xo, $f);
        $b = S::wayTransfer($high, $xo, null, $f);

        expect(S::db([$a[0] + $b[0], $a[1] + $b[1]]))->toEqualWithDelta(85.0, 1.0);
    }

    // each way is 6 dB down at the crossover
    expect(S::db(S::wayTransfer($low, null, $xo, $xo)))->toEqualWithDelta(79.0, 0.1)
        ->and(S::db(S::wayTransfer($high, $xo, null, $xo)))->toEqualWithDelta(79.0, 0.1);
});

it('finds a flat crossover for driver pairs', function () {
    $pairs = [
        [woofer(), tweeter(), 800, 5000],
        [S::driverSpec(['type' => 'subwoofer', 'fs' => 26, 'qes' => 0.42, 'qms' => 4.6, 're' => 3.2, 'sd_cm2' => 540, 'bl' => 14.6]), woofer(), 60, 300],
    ];

    foreach ($pairs as [$low, $high, $min, $max]) {
        $xo = S::bestCrossover($low, $high, $min, $max);

        expect($xo)->toBeGreaterThanOrEqual($min)->toBeLessThanOrEqual($max)
            ->and(S::crossoverDeviation($low, $high, $xo))->toBeLessThan(1.0);
    }
});

it('adds up to a flat curve in the frequency response viewer (private disk, complex sum)', function () {
    Storage::fake('private');
    $low = woofer();
    $high = tweeter();
    $xo = 2000.0;
    $freqs = S::frequencies(10, 40000, 100);

    Storage::disk('private')->put('designs/k/lf.frd', S::wayFrd($low, null, $xo, 'LF', $freqs));
    Storage::disk('private')->put('designs/k/hf.frd', S::wayFrd($high, $xo, null, 'HF', $freqs));
    $design = Design::factory()->create(['frd_files' => ['designs/k/lf.frd', 'designs/k/hf.frd']]);

    $viewer = Livewire::test(FrequencyResponseViewer::class, ['design' => $design]);

    expect($viewer->get('chartData'))->toHaveCount(2)
        ->and($viewer->get('phaseData'))->toHaveCount(2);

    $sum = collect($viewer->get('summedResponse'));
    expect($sum->count())->toBeGreaterThan(100);

    $region = $sum->filter(fn ($p) => $p['x'] >= $xo / 1.5 && $p['x'] <= $xo * 1.5);
    expect($region->count())->toBeGreaterThan(10);
    foreach ($region as $point) {
        expect($point['y'])->toEqualWithDelta(85.0, 1.0);
    }
});

it('reads the viewer files from the private disk only', function () {
    Storage::fake('private');
    Storage::fake('public');
    Storage::disk('public')->put('designs/k/lf.frd', S::wayFrd(woofer(), null, 2000, 'LF', S::frequencies(10, 40000, 50)));
    $design = Design::factory()->create(['frd_files' => ['designs/k/lf.frd']]);

    expect(Livewire::test(FrequencyResponseViewer::class, ['design' => $design])->get('chartData'))->toBe([]);
});
