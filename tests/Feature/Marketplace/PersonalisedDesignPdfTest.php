<?php

use App\Enums\EntitlementSource;
use App\Models\Design;
use App\Models\DesignDriver;
use App\Models\DesignSnapshot;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Services\Snapshots\DesignPdf;
use App\Services\Snapshots\DesignSnapshotBuilder;
use App\Services\Snapshots\PdfLicense;
use App\Services\Snapshots\PersonalisedSnapshotDownload;
use App\Support\PlaceholderImage;
use Illuminate\Support\Facades\Storage;

/*
 * Every download of a design snapshot gets a PDF with the downloader's name in the footer of every page
 * (PersonalisedSnapshotDownload); the stored snapshot keeps the generic PDF.
 */

beforeEach(function () {
    Storage::fake('private');
    Storage::fake('public');
});

const PDF_KEY = 'cccccccc-0000-4000-8000-000000000001';

/** All the text of a dompdf PDF (the glyph codes in its content streams are the characters, UTF-16BE). */
function pdfText(string $pdf): string
{
    $text = '';
    // dompdf writes "stream\n", the data and "\nendstream" (the data may itself end in a carriage return)
    preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $streams);

    foreach ($streams[1] as $raw) {
        $data = @gzuncompress($raw);

        if ($data === false || ! str_contains($data, ' Tf')) {
            continue;
        }

        $length = strlen($data);
        $line = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $data[$i];

            if ($char === '(') {
                $string = '';

                for ($i++; $i < $length && $data[$i] !== ')'; $i++) {
                    if ($data[$i] === '\\') {
                        $i++;
                        if (ctype_digit($data[$i])) {
                            $octal = $data[$i];
                            while (strlen($octal) < 3 && isset($data[$i + 1]) && ctype_digit($data[$i + 1])) {
                                $octal .= $data[++$i];
                            }
                            $string .= chr(octdec($octal));
                        } else {
                            $string .= ['n' => "\n", 'r' => "\r", 't' => "\t"][$data[$i]] ?? $data[$i];
                        }
                    } else {
                        $string .= $data[$i];
                    }
                }

                $line .= mb_convert_encoding($string, 'UTF-8', 'UTF-16BE');
            } elseif ($char === 'T' && ($data[$i + 1] ?? '') === 'J' || $char === 'T' && ($data[$i + 1] ?? '') === 'j') {
                $text .= $line."\n";
                $line = '';
            }
        }
    }

    return $text;
}

function pdfPageCount(string $pdf): int
{
    return preg_match_all('~/Type /Page\b~', $pdf);
}

/** @return array<string, string> entry name => contents, in the order of the ZIP */
function zipContents(string $bytes): array
{
    $file = tempnam(sys_get_temp_dir(), 'pdfzip');
    file_put_contents($file, $bytes);

    $zip = new ZipArchive();
    expect($zip->open($file))->toBeTrue();

    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
    }

    $zip->close();
    unlink($file);

    return $entries;
}

/** The PDF entry of a ZIP (the only root-level .pdf). */
function zipPdf(array $entries): string
{
    $names = array_values(array_filter(array_keys($entries), fn ($name) => ! str_contains($name, '/') && str_ends_with($name, '.pdf')));
    expect($names)->toHaveCount(1);

    return $entries[$names[0]];
}

/**
 * A designer's paid design with files of its own (one of them random bytes), a component with specifications, a bill of
 * materials and $paragraphs of description, and its snapshot built by the real builder.
 */
function pdfSnapshot(int $paragraphs = 1, array $design = []): DesignSnapshot
{
    static $n = 0;
    $n++;
    $designer = User::factory()->onboardedSeller()->create(['name' => 'Dora Designer', 'username' => 'doradesigns'.$n, 'email' => "dora-private{$n}@example.test"]);
    $key = $design['storage_key'] ?? PDF_KEY;

    $private = Storage::disk('private');
    $private->put("designs/{$key}/Other Files/blob.bin", random_bytes(40000));
    $private->put("designs/{$key}/Enclosure/cutlist.csv", "part,qty\nside,2\n");
    $private->put("designs/{$key}/LF/Frequency/a16.frd", "* a16\n100 87 0\n");
    Storage::disk('public')->put("designs/{$key}/Attachments/cover.jpg", PlaceholderImage::design(3, 2));

    $model = Design::factory()->for($designer, 'designer')->paid(1500)->create(array_merge([
        'name' => 'Harbor 2',
        'tag' => 'A compact two-way',
        'storage_key' => $key,
        'summary' => '<p>The summary of Harbor 2.</p>',
        'description' => str_repeat('<p>Build it with patience and a sharp saw. Measure twice, cut once, and glue every joint.</p>', $paragraphs),
        'bill_of_materials' => ['Cabinet' => '18 mm birch plywood'],
        'design_other_files' => ["designs/{$key}/Other Files/blob.bin"],
        'enclosure_files' => ["designs/{$key}/Enclosure/cutlist.csv"],
        'card_image' => ["designs/{$key}/Attachments/cover.jpg"],
    ], $design));

    DesignDriver::factory()->for($model)->for(Driver::factory()->create(['brand' => 'Aurelia', 'model' => 'A16-W']))->create([
        'position' => 'LF',
        'quantity' => 2,
        'low_frequency' => 40,
        'high_frequency' => 2500,
        'specifications' => ['Fs' => '38 Hz', 'Qts' => '0.35'],
        'frequency_files' => ["designs/{$key}/LF/Frequency/a16.frd"],
    ]);

    return app(DesignSnapshotBuilder::class)->build($model->refresh(), 'v1.0');
}

function pdfDownload($test, User $user, DesignSnapshot $snapshot): array
{
    return zipContents($test->actingAs($user)->get(route('design-snapshots.download', $snapshot))->assertOk()->streamedContent());
}

function pdfBuyer(DesignSnapshot $snapshot, array $attributes = []): User
{
    $buyer = User::factory()->create($attributes + ['name' => 'Bea Buyer', 'username' => 'beabuys', 'email' => 'bea-private@example.test']);
    Order::factory()->for($buyer, 'user')->paid([$snapshot->design])->create(['number' => 'SDL-PDF123']);

    return $buyer;
}

// --- Who the PDF is for -------------------------------------------------------------------------

it('prints the downloader in the PDF of the ZIP and nobody else', function () {
    $snapshot = pdfSnapshot();
    $bea = pdfBuyer($snapshot);
    $carl = User::factory()->create(['name' => 'Carl Collector', 'username' => 'carlc', 'email' => 'carl-private@example.test']);
    $carl->grantEntitlement($snapshot->design);

    $forBea = pdfText(zipPdf(pdfDownload($this, $bea, $snapshot)));
    $forCarl = pdfText(zipPdf(pdfDownload($this, $carl, $snapshot)));

    expect($forBea)->toContain('Licensed to Bea Buyer (@beabuys)')
        ->and($forBea)->not->toContain('Carl')->not->toContain('carlc')
        ->and($forCarl)->toContain('Licensed to Carl Collector (@carlc)')
        ->and($forCarl)->not->toContain('Bea Buyer')->not->toContain('beabuys');
});

it('never prints the e-mail address, and the PDF still shows the design', function () {
    $snapshot = pdfSnapshot();
    $bea = pdfBuyer($snapshot);

    $pdf = zipPdf(pdfDownload($this, $bea, $snapshot));
    $text = pdfText($pdf);

    expect($text)->not->toContain('bea-private')->not->toContain('@example.test')->not->toContain('dora-private')
        ->and($text)->toContain('Harbor 2')->toContain('Dora Designer')->toContain('v1.0')
        ->and($text)->toContain('The summary of Harbor 2.')->toContain('18 mm birch plywood')->toContain('Aurelia A16-W')
        ->and($text)->toContain('For personal use')->toContain('redistribute')
        ->and($text)->toContain(rtrim((string) config('app.url'), '/'))
        ->and($text)->toContain('downloaded '.now()->format('M j, Y'));
});

it('puts the footer on every page, with page numbers', function () {
    $snapshot = pdfSnapshot(paragraphs: 140);
    $bea = pdfBuyer($snapshot);

    $pdf = zipPdf(pdfDownload($this, $bea, $snapshot));
    $pages = pdfPageCount($pdf);
    $text = pdfText($pdf);

    expect($pages)->toBeGreaterThan(2)
        ->and(substr_count($text, 'Licensed to Bea Buyer (@beabuys)'))->toBe($pages)
        ->and(substr_count($text, "For personal use — please don't redistribute."))->toBe($pages)
        ->and($text)->toContain('Page 1 of '.$pages)->toContain("Page {$pages} of {$pages}");
});

it('escapes the name instead of interpreting it', function () {
    $snapshot = pdfSnapshot();
    $name = '<b>Eve</b> & "Q" <script>alert(1)</script>';
    $eve = pdfBuyer($snapshot, ['name' => $name, 'username' => 'eve_q']);

    $text = pdfText(zipPdf(pdfDownload($this, $eve, $snapshot)));

    expect($text)->toContain('Licensed to '.$name.' (@eve_q)');
});

it('keeps the other ZIP entries exactly as they are stored', function () {
    $snapshot = pdfSnapshot();
    $bea = pdfBuyer($snapshot);

    $stored = zipContents(Storage::disk('private')->get($snapshot->download_path));
    $served = pdfDownload($this, $bea, $snapshot);
    $pdfName = collect(array_keys($stored))->first(fn ($name) => str_ends_with($name, '.pdf'));

    expect(array_keys($served))->toBe(array_keys($stored))
        ->and($pdfName)->toBe('harbor-2-v1.0.pdf');

    foreach ($stored as $name => $contents) {
        if ($name !== $pdfName) {
            expect($served[$name])->toBe($contents, "entry {$name} changed");
        }
    }

    expect(strlen($stored['other/blob.bin']))->toBe(40000)
        ->and($served[$pdfName])->not->toBe($stored[$pdfName])
        ->and($served[$pdfName])->toStartWith('%PDF')
        ->and(pdfText($stored[$pdfName]))->not->toContain('Licensed to');
});

it('keeps the stored snapshot untouched: its PDF stays the generic copy', function () {
    $snapshot = pdfSnapshot();
    $before = Storage::disk('private')->get($snapshot->download_path);

    pdfDownload($this, pdfBuyer($snapshot), $snapshot);

    expect(Storage::disk('private')->get($snapshot->download_path))->toBe($before)
        ->and(pdfText(zipPdf(zipContents($before))))->toContain('Page 1 of')->not->toContain('Bea Buyer');
});

it('renders the snapshot as it was, not the design as it is now', function () {
    $snapshot = pdfSnapshot();
    $snapshot->design->update(['name' => 'Harbor 3 (renamed)', 'bill_of_materials' => ['Cabinet' => 'Concrete']]);

    $text = pdfText(zipPdf(pdfDownload($this, pdfBuyer($snapshot), $snapshot)));

    expect($text)->toContain('Harbor 2')->toContain('18 mm birch plywood')
        ->and($text)->not->toContain('renamed')->not->toContain('Concrete');
});

it('leaves no temporary files behind', function () {
    $snapshot = pdfSnapshot();
    $bea = pdfBuyer($snapshot);
    $before = glob(sys_get_temp_dir().'/*') ?: [];

    $this->actingAs($bea)->get(route('design-snapshots.download', $snapshot))->assertOk()->streamedContent();

    expect(array_values(array_diff(glob(sys_get_temp_dir().'/*') ?: [], $before)))->toBe([]);
});

// --- Access -------------------------------------------------------------------------------------

it('still sends guests to the login and refuses strangers and revoked buyers', function () {
    $snapshot = pdfSnapshot();

    $this->get(route('design-snapshots.download', $snapshot))->assertRedirect();
    $this->actingAs(User::factory()->create())->get(route('design-snapshots.download', $snapshot))->assertForbidden();

    $revoked = User::factory()->create();
    $revoked->grantEntitlement($snapshot->design)->revoke();
    $this->actingAs($revoked)->get(route('design-snapshots.download', $snapshot))->assertForbidden();

    expect(Storage::disk('private')->exists(PersonalisedSnapshotDownload::cacheDirectory($snapshot)))->toBeFalse();
});

it('answers 404 when the stored ZIP is gone', function () {
    $snapshot = pdfSnapshot();
    Storage::disk('private')->delete($snapshot->download_path);

    $this->actingAs($snapshot->design->designer)->get(route('design-snapshots.download', $snapshot))->assertNotFound();
});

// --- The label ----------------------------------------------------------------------------------

it('says where the copy comes from: order, free download, designer copy, admin copy', function () {
    $snapshot = pdfSnapshot();
    $design = $snapshot->design;

    $buyer = pdfBuyer($snapshot);
    $free = User::factory()->create(['name' => 'Fay Free', 'username' => 'fayfree']);
    $admin = User::factory()->admin()->create(['name' => 'Adam Admin', 'username' => 'adamadmin']);
    $granted = User::factory()->create(['name' => 'Gus Granted', 'username' => 'gusg']);
    $granted->grantEntitlement($design, EntitlementSource::ADMIN_GRANT);

    // a free design: the first download records the free entitlement
    $freeDesign = pdfSnapshot(design: ['storage_key' => 'cccccccc-0000-4000-8000-000000000002', 'name' => 'Free Fun', 'price_cents' => 0]);

    $labels = [
        [$buyer, $snapshot, 'Order SDL-PDF123'],
        [$design->designer, $snapshot, 'Designer copy'],
        [$admin, $snapshot, 'Admin copy'],
        [$granted, $snapshot, 'Complimentary copy'],
        [$free, $freeDesign, 'Free download'],
    ];

    foreach ($labels as [$user, $target, $label]) {
        $text = pdfText(zipPdf(pdfDownload($this, $user, $target)));

        expect($text)->toContain(' · '.$label.' · ');
    }
});

it('uses the buyer order number, not the admin label, for a purchase of an ordinary user', function () {
    $snapshot = pdfSnapshot();
    $buyer = pdfBuyer($snapshot);

    expect(PdfLicense::labelFor($buyer, $snapshot->design))->toBe('Order SDL-PDF123')
        ->and(PdfLicense::for($buyer, $snapshot->design)->segments())->toBe([
            'Licensed to Bea Buyer (@beabuys)',
            'downloaded '.now()->format('M j, Y'),
            'Order SDL-PDF123',
            rtrim((string) config('app.url'), '/'),
        ]);
});

// --- The cache ----------------------------------------------------------------------------------

it('caches the personalised PDF per snapshot and user and reuses it', function () {
    $snapshot = pdfSnapshot();
    $bea = pdfBuyer($snapshot);
    $disk = Storage::disk('private');
    $directory = PersonalisedSnapshotDownload::cacheDirectory($snapshot);

    expect($directory)->toBe('designs/'.PDF_KEY.'/snapshots/personalised/'.$snapshot->id);

    pdfDownload($this, $bea, $snapshot);
    $files = $disk->files($directory);

    expect($files)->toHaveCount(1)
        ->and(basename($files[0]))->toStartWith('u'.$bea->id.'-'.$snapshot->updated_at->getTimestamp().'-')
        ->and($disk->get($files[0]))->toStartWith('%PDF');

    // a second download is served from the cache: swap the cached file for another PDF and it shows up in the ZIP
    $disk->put($files[0], '%PDF-1.4 cached copy');
    expect(zipPdf(pdfDownload($this, $bea, $snapshot)))->toBe('%PDF-1.4 cached copy')
        ->and($disk->files($directory))->toBe($files);

    // another user gets a PDF (and a cache entry) of their own
    $carl = User::factory()->create(['name' => 'Carl Collector', 'username' => 'carlc']);
    $carl->grantEntitlement($snapshot->design);
    expect(pdfText(zipPdf(pdfDownload($this, $carl, $snapshot))))->toContain('Carl Collector')
        ->and($disk->files($directory))->toHaveCount(2);
});

it('does not serve a cached PDF that is not a PDF', function () {
    $snapshot = pdfSnapshot();
    $bea = pdfBuyer($snapshot);
    pdfDownload($this, $bea, $snapshot);

    $file = Storage::disk('private')->files(PersonalisedSnapshotDownload::cacheDirectory($snapshot))[0];
    Storage::disk('private')->put($file, 'garbage');

    expect(pdfText(zipPdf(pdfDownload($this, $bea, $snapshot))))->toContain('Licensed to Bea Buyer');
});

it('renders a new PDF, and drops the old one, when what the footer says changes', function () {
    $snapshot = pdfSnapshot();
    $bea = pdfBuyer($snapshot);
    $disk = Storage::disk('private');
    $directory = PersonalisedSnapshotDownload::cacheDirectory($snapshot);

    pdfDownload($this, $bea, $snapshot);
    $first = $disk->files($directory)[0];

    $bea->update(['name' => 'Beatrice Buyer']);
    $text = pdfText(zipPdf(pdfDownload($this, $bea->refresh(), $snapshot)));

    expect($text)->toContain('Licensed to Beatrice Buyer')->not->toContain('Bea Buyer')
        ->and($disk->files($directory))->toHaveCount(1)
        ->and($disk->files($directory)[0])->not->toBe($first);

    // and tomorrow's download says tomorrow's date
    $this->travel(1)->days();
    $tomorrow = pdfText(zipPdf(pdfDownload($this, $bea, $snapshot)));
    expect($tomorrow)->toContain('downloaded '.now()->format('M j, Y'))
        ->and($disk->files($directory))->toHaveCount(1);
});

it('clears the cache when the snapshot is rebuilt or deleted', function () {
    $snapshot = pdfSnapshot();
    $bea = pdfBuyer($snapshot);
    $disk = Storage::disk('private');
    $directory = PersonalisedSnapshotDownload::cacheDirectory($snapshot);

    pdfDownload($this, $bea, $snapshot);
    expect($disk->files($directory))->toHaveCount(1);

    // rebuilt (same version, same row): the PDFs made from the old build go
    $design = $snapshot->design->refresh();
    $design->update(['name' => 'Harbor 2 Mk II']);
    $rebuilt = app(DesignSnapshotBuilder::class)->build($design, 'v1.0');

    expect($rebuilt->id)->toBe($snapshot->id)
        ->and($disk->exists($directory))->toBeFalse();

    $text = pdfText(zipPdf(pdfDownload($this, $bea, $rebuilt)));
    expect($text)->toContain('Harbor 2 Mk II')
        ->and($disk->files($directory))->toHaveCount(1);

    // deleted: ZIP and cache go
    $rebuilt->delete();
    expect($disk->exists($directory))->toBeFalse()
        ->and($disk->exists($rebuilt->download_path))->toBeFalse();
});

it('removes the cache together with the design', function () {
    $snapshot = pdfSnapshot();
    pdfDownload($this, $snapshot->design->designer, $snapshot);
    $directory = PersonalisedSnapshotDownload::cacheDirectory($snapshot);

    expect(Storage::disk('private')->exists($directory))->toBeTrue();

    $snapshot->design->delete();

    expect(Storage::disk('private')->exists($directory))->toBeFalse();
});

// --- Fallbacks ----------------------------------------------------------------------------------

it('serves the stored ZIP unchanged when the PDF cannot be rendered', function () {
    $snapshot = pdfSnapshot();
    $this->mock(DesignPdf::class)->shouldReceive('render')->andThrow(new RuntimeException('dompdf is unhappy'));

    $response = $this->actingAs($snapshot->design->designer)->get(route('design-snapshots.download', $snapshot))->assertOk();

    expect($response->streamedContent())->toBe(Storage::disk('private')->get($snapshot->download_path));
});

it('serves a ZIP without a PDF unchanged', function () {
    $design = Design::factory()->withSnapshot('v1.0', true)->create();
    $snapshot = $design->snapshots()->first();

    $response = $this->actingAs(User::factory()->create())->get(route('design-snapshots.download', $snapshot))->assertOk();

    expect($response->streamedContent())->toBe(Storage::disk('private')->get($snapshot->download_path))
        ->and(Storage::disk('private')->exists(PersonalisedSnapshotDownload::cacheDirectory($snapshot)))->toBeFalse();
});

// --- Streaming ----------------------------------------------------------------------------------

it('streams large ZIPs without holding them in memory', function () {
    $snapshot = pdfSnapshot();
    $bea = pdfBuyer($snapshot);

    // a stored ZIP with 2 x 32 MB of incompressible data next to the PDF
    $big = tempnam(sys_get_temp_dir(), 'big');
    $handle = fopen($big, 'wb');
    for ($i = 0; $i < 32; $i++) {
        fwrite($handle, random_bytes(1024 * 1024));
    }
    fclose($handle);

    $path = Storage::disk('private')->path($snapshot->download_path);
    $zip = new ZipArchive();
    $zip->open($path);
    foreach (['other/huge.bin', 'other/huge-2.bin'] as $name) {
        $zip->addFile($big, $name);
        $zip->setCompressionName($name, ZipArchive::CM_STORE);
    }
    $zip->close();

    // the first download renders and caches the PDF; measure the second one, which is only the copy
    pdfDownload($this, $bea, $snapshot);
    $hash = md5_file($big);

    $out = tempnam(sys_get_temp_dir(), 'out');
    $sink = fopen($out, 'wb');
    $response = app(PersonalisedSnapshotDownload::class)->response($snapshot->refresh(), $bea);

    memory_reset_peak_usage();
    $baseline = memory_get_peak_usage();
    ob_start(function (string $chunk) use ($sink) {
        fwrite($sink, $chunk);

        return '';
    }, 65536);
    $response->sendContent();
    ob_end_clean();
    $growth = memory_get_peak_usage() - $baseline;
    fclose($sink);

    // ZipStream reads in blocks of up to 16 MB, whatever the size of the entries: far below the 64 MB that went through
    expect($growth)->toBeLessThan(32 * 1024 * 1024)
        ->and(filesize($out))->toBeGreaterThan(64 * 1024 * 1024);

    $result = new ZipArchive();
    expect($result->open($out))->toBeTrue()
        ->and(md5($result->getFromName('other/huge.bin')))->toBe($hash)
        ->and(md5($result->getFromName('other/huge-2.bin')))->toBe($hash)
        ->and(strlen((string) $result->getFromName('other/blob.bin')))->toBe(40000);
    $result->close();

    unlink($big);
    unlink($out);
});

// --- The PDF itself -----------------------------------------------------------------------------

it('renders a design with hardly any data', function () {
    $pdf = app(DesignPdf::class)->render(['name' => 'Bare', 'version' => 'v0']);

    expect($pdf)->toStartWith('%PDF')
        ->and(pdfText($pdf))->toContain('Bare')->toContain('v0')
        ->and(pdfText($pdf))->not->toContain('Licensed to');

    $licensed = app(DesignPdf::class)->render([], new PdfLicense('Zed', null, now(), 'Free download', 'https://sdlabs.test'));
    expect(pdfText($licensed))->toContain('Licensed to Zed · downloaded')->not->toContain('(@');
});

it('has no remote resources and no scripts in the HTML it renders', function () {
    $html = view('pdf.Design', [
        'pdf' => app(DesignPdf::class)->viewData([
            'name' => 'Harbor 2',
            'summary' => '<p>Hi</p>',
            'images' => ['designs/nope.jpg'],
        ]),
        'license' => new PdfLicense('Zed', 'zed', now(), 'Free download', 'https://sdlabs.test'),
    ])->render();

    expect($html)->not->toMatch('/(src|href)=["\']https?:/')->not->toContain('url(http')->not->toContain('@import')->not->toContain('<script');
});
