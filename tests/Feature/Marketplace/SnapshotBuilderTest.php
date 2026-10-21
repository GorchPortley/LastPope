<?php

use App\Jobs\BuildDesignSnapshot;
use App\Jobs\BuildDriverSnapshot;
use App\Models\Design;
use App\Models\DesignDriver;
use App\Models\Driver;
use App\Models\DesignSnapshot;
use App\Models\User;
use App\Notifications\SnapshotReady;
use App\Services\Snapshots\DesignSnapshotBuilder;
use App\Services\Snapshots\DriverSnapshotBuilder;
use App\Services\Snapshots\PdfImages;
use App\Support\PlaceholderImage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
    Storage::fake('public');
});

/** @return array<int, string> entry names of a ZIP stored on the private disk */
function zipEntries(string $path): array
{
    $zip = new ZipArchive();
    $zip->open(Storage::disk('private')->path($path));
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();

    return $names;
}

function zipRead(string $path, string $entry): string
{
    $zip = new ZipArchive();
    $zip->open(Storage::disk('private')->path($path));
    $contents = $zip->getFromName($entry);
    $zip->close();

    return $contents;
}

/** A design with real files on the private disk, a component per band and a card image on the public disk. */
function designWithFiles(): Design
{
    $design = Design::factory()->for(onboardedSeller(), 'designer')->paid()->create(['name' => 'Harbor 2', 'storage_key' => 'aaaaaaaa-0000-4000-8000-000000000001']);
    $private = Storage::disk('private');

    $paths = [
        'frd_files' => ['designs/aaaaaaaa-0000-4000-8000-000000000001/Attachments/Display Responses/LF.frd' => "* lf\n100 85 0\n"],
        'enclosure_files' => ['designs/aaaaaaaa-0000-4000-8000-000000000001/Enclosure/cutlist.csv' => "part,qty\nside,2\n"],
        'electronic_files' => ['designs/aaaaaaaa-0000-4000-8000-000000000001/Electronics/xo.txt' => 'xo'],
        'design_other_files' => ['designs/aaaaaaaa-0000-4000-8000-000000000001/Other Files/notes.txt' => 'notes'],
    ];
    $update = [];
    foreach ($paths as $column => $files) {
        foreach ($files as $path => $contents) {
            $private->put($path, $contents);
        }
        $update[$column] = array_keys($files);
    }

    $imagePath = 'designs/aaaaaaaa-0000-4000-8000-000000000001/Attachments/Display Images/cover.jpg';
    Storage::disk('public')->put($imagePath, PlaceholderImage::design(3, 2));
    $update['card_image'] = [$imagePath];
    $design->update($update);

    $driver = Driver::factory()->create(['brand' => 'Aurelia', 'model' => 'A16-W']);
    $private->put('designs/aaaaaaaa-0000-4000-8000-000000000001/LF/Frequency/a16.frd', "* a16\n100 87 0\n");
    $private->put('designs/aaaaaaaa-0000-4000-8000-000000000001/LF/Impedance/a16.zma', "* z\n100 6 0\n");
    DesignDriver::factory()->for($design)->for($driver)->create([
        'position' => 'LF',
        'frequency_files' => ['designs/aaaaaaaa-0000-4000-8000-000000000001/LF/Frequency/a16.frd'],
        'impedance_files' => ['designs/aaaaaaaa-0000-4000-8000-000000000001/LF/Impedance/a16.zma'],
        'other_files' => ['designs/aaaaaaaa-0000-4000-8000-000000000001/LF/Other/missing.txt'], // listed but not on disk
    ]);

    return $design->refresh();
}

it('builds a ZIP from the stored file paths with the PDF, manifest and README', function () {
    $design = designWithFiles();

    $snapshot = app(DesignSnapshotBuilder::class)->build($design, 'v1.0');
    $path = 'designs/aaaaaaaa-0000-4000-8000-000000000001/snapshots/harbor-2-v1.0.zip';

    expect($snapshot->download_path)->toBe($path)
        ->and($snapshot->snapshot_name)->toBe('harbor-2-v1.0.zip')
        ->and($snapshot->version)->toBe('v1.0')
        ->and($snapshot->file_size)->toBe(Storage::disk('private')->size($path))
        ->and(Storage::disk('private')->exists($path))->toBeTrue();

    $entries = zipEntries($path);
    expect($entries)->toContain(
        'response-previews/LF.frd',
        'enclosure/cutlist.csv',
        'electronics/xo.txt',
        'other/notes.txt',
        'components/LF-aurelia-a16-w/frequency/a16.frd',
        'components/LF-aurelia-a16-w/impedance/a16.zma',
        'harbor-2-v1.0.pdf',
        'manifest.json',
        'README.txt',
    );

    // the content is the stored file, byte for byte
    expect(zipRead($path, 'enclosure/cutlist.csv'))->toBe("part,qty\nside,2\n")
        ->and(zipRead($path, 'components/LF-aurelia-a16-w/frequency/a16.frd'))->toBe("* a16\n100 87 0\n")
        ->and(zipRead($path, 'harbor-2-v1.0.pdf'))->toStartWith('%PDF');
});

it('records the design as it was in manifest.json and stashed_data', function () {
    $design = designWithFiles();
    $snapshot = app(DesignSnapshotBuilder::class)->build($design, 'v1.0');

    $manifest = json_decode(zipRead($snapshot->download_path, 'manifest.json'), true);

    expect($manifest)->toBe($snapshot->stashed_data)
        ->and($manifest['name'])->toBe('Harbor 2')
        ->and($manifest['version'])->toBe('v1.0')
        ->and($manifest['price_cents'])->toBe($design->price_cents)
        ->and($manifest['components'])->toHaveCount(1)
        ->and($manifest['components'][0]['driver'])->toBe('Aurelia A16-W')
        ->and(collect($manifest['files'])->pluck('path')->all())->toContain('enclosure/cutlist.csv')
        ->and($manifest['missing_files'])->toBe(['designs/aaaaaaaa-0000-4000-8000-000000000001/LF/Other/missing.txt'])
        ->and(zipRead($snapshot->download_path, 'README.txt'))->toContain('Harbor 2')->toContain('could not be found');
});

it('embeds images in the PDF from the disk, not from URLs', function () {
    $design = designWithFiles();
    $snapshot = app(DesignSnapshotBuilder::class)->build($design, 'v1.0');

    $pdf = zipRead($snapshot->download_path, 'harbor-2-v1.0.pdf');

    expect($pdf)->toContain('/Subtype /Image')
        ->and(strlen($pdf))->toBeGreaterThan(15000);
});

it('turns storage URLs in rich text into embedded images', function () {
    Storage::disk('public')->put('designs/x/Attachments/a.jpg', PlaceholderImage::driver(1));
    $images = app(PdfImages::class);

    $html = $images->inlineHtml('<p><img alt="a" src="http://localhost/storage/designs/x/Attachments/a.jpg"></p><img src="http://elsewhere.test/b.jpg"><img src="/storage/../../etc/passwd">');

    expect($html)->toContain('src="data:image/jpeg;base64,')
        ->and($html)->toContain('src="http://elsewhere.test/b.jpg"')   // remote images are left alone (and never fetched)
        ->and($html)->toContain('src="/storage/../../etc/passwd"')      // paths that escape the disk are ignored
        ->and($images->embed('../secret.jpg'))->toBeNull()
        ->and($images->embed('designs/x/Attachments/nope.jpg'))->toBeNull();
});

it('replaces a version that is built again instead of adding a second row', function () {
    $design = designWithFiles();
    $builder = app(DesignSnapshotBuilder::class);

    $first = $builder->build($design, 'v1.0');
    Storage::disk('private')->put('designs/aaaaaaaa-0000-4000-8000-000000000001/Enclosure/extra.txt', 'later');
    $design->update(['enclosure_files' => [...$design->enclosure_files, 'designs/aaaaaaaa-0000-4000-8000-000000000001/Enclosure/extra.txt']]);
    $second = $builder->build($design->refresh(), 'v1.0');

    expect(DesignSnapshot::where('design_id', $design->id)->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and(zipEntries($second->download_path))->toContain('enclosure/extra.txt');

    $builder->build($design, 'v2.0');
    expect($design->snapshots()->count())->toBe(2);
});

it('keeps file names unique when two versions slug alike', function () {
    $design = designWithFiles();
    $builder = app(DesignSnapshotBuilder::class);

    $a = $builder->build($design, 'v1.0');
    $b = $builder->build($design, 'v1 0');

    expect($a->download_path)->not->toBe($b->download_path)
        ->and(Storage::disk('private')->exists($a->download_path))->toBeTrue()
        ->and(Storage::disk('private')->exists($b->download_path))->toBeTrue();
});

it('deletes the ZIP together with the snapshot row', function () {
    $snapshot = app(DesignSnapshotBuilder::class)->build(designWithFiles(), 'v1.0');

    $snapshot->delete();

    expect(Storage::disk('private')->exists($snapshot->download_path))->toBeFalse();
});

it('builds a driver data pack with frequency, impedance and other files', function () {
    $driver = Driver::factory()->create(['brand' => 'Aurelia', 'model' => 'A16-W', 'storage_key' => 'bbbbbbbb-0000-4000-8000-000000000001']);
    $private = Storage::disk('private');
    $private->put('drivers/bbbbbbbb-0000-4000-8000-000000000001/Frequency/a.frd', "100 87 0\n");
    $private->put('drivers/bbbbbbbb-0000-4000-8000-000000000001/Impedance/a.zma', "100 6 0\n");
    $private->put('drivers/bbbbbbbb-0000-4000-8000-000000000001/Other/datasheet.txt', 'sheet');
    $driver->update([
        'frequency_files' => ['drivers/bbbbbbbb-0000-4000-8000-000000000001/Frequency/a.frd'],
        'impedance_files' => ['drivers/bbbbbbbb-0000-4000-8000-000000000001/Impedance/a.zma'],
        'other_files' => ['drivers/bbbbbbbb-0000-4000-8000-000000000001/Other/datasheet.txt'],
        'factory_specs' => ['Fs' => '38'],
    ]);

    $snapshot = app(DriverSnapshotBuilder::class)->build($driver->refresh(), 'v1.0');

    expect($snapshot->download_path)->toBe('drivers/bbbbbbbb-0000-4000-8000-000000000001/snapshots/aurelia-a16-w-v1.0.zip')
        ->and($snapshot->file_size)->toBe($private->size($snapshot->download_path))
        ->and(zipEntries($snapshot->download_path))->toBe([
            'frequency/a.frd', 'impedance/a.zma', 'other/datasheet.txt', 'manifest.json', 'README.txt',
        ])
        ->and(json_decode(zipRead($snapshot->download_path, 'manifest.json'), true)['factory_specs'])->toBe(['Fs' => '38'])
        ->and($snapshot->driver->is($driver))->toBeTrue();
});

it('runs from a queued job and tells the owner when the snapshot is ready', function () {
    $design = designWithFiles();

    BuildDesignSnapshot::dispatchSync($design, 'v1.0', $design->designer);

    expect($design->snapshots()->count())->toBe(1);

    $notification = $design->designer->notifications()->first();
    expect($notification->type)->toBe(SnapshotReady::class)
        ->and($notification->data['status'])->toBe('ready')
        ->and($notification->data['body'])->toContain('v1.0')->toContain('Harbor 2');
});

it('also tells the admin who requested the snapshot', function () {
    $design = designWithFiles();
    $admin = User::factory()->admin()->create();

    BuildDesignSnapshot::dispatchSync($design, 'v1.0', $admin);

    expect($design->designer->notifications()->count())->toBe(1)
        ->and($admin->notifications()->count())->toBe(1);
});

it('queues the jobs', function () {
    Queue::fake();
    $design = Design::factory()->create();
    $driver = Driver::factory()->create();

    BuildDesignSnapshot::dispatch($design, 'v1', $design->designer);
    BuildDriverSnapshot::dispatch($driver, 'v1', $driver->creator);

    Queue::assertPushed(BuildDesignSnapshot::class, fn ($job) => $job->version === 'v1' && $job->requestedBy->is($design->designer));
    Queue::assertPushed(BuildDriverSnapshot::class);
});

it('tells the owner when building fails', function () {
    $design = Design::factory()->create(['name' => 'Broken']);

    (new BuildDesignSnapshot($design, 'v9', $design->designer))->failed(new RuntimeException('disk full'));

    $data = $design->designer->notifications()->first()->data;
    expect($data['status'])->toBe('failed')->and($data['body'])->toContain('disk full');
});

it('runs the driver job and notifies the creator', function () {
    $driver = Driver::factory()->create();

    BuildDriverSnapshot::dispatchSync($driver, 'v1.0', $driver->creator);

    expect($driver->snapshots()->count())->toBe(1)
        ->and($driver->creator->notifications()->first()->data['kind'])->toBe('driver');
});
