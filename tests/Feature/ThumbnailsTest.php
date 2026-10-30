<?php

use App\Models\Design;
use App\Models\Driver;
use App\Support\Thumbnails;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

function storedPhoto(string $path = 'designs/k1/Attachments/Display Images/cover.jpg', int $width = 2000, int $height = 1500): string
{
    $file = UploadedFile::fake()->image('x.jpg', $width, $height);
    Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));

    return $path;
}

it('hands out a thumbnail URL with the width and the original modification time, and the original for anything else', function () {
    $path = storedPhoto();
    $mtime = Storage::disk('public')->lastModified($path);

    expect(Thumbnails::url($path, 480))->toEndWith('/storage/thumbs/480/'.$path.'.'.$mtime.'.webp')
        ->and(Thumbnails::url('/'.$path, 480))->toEndWith('/storage/thumbs/480/'.$path.'.'.$mtime.'.webp')
        ->and(Thumbnails::url($path, 333))->toEndWith('/storage/'.$path)
        ->and(Thumbnails::url('designs/missing.jpg', 480))->toEndWith('/storage/designs/missing.jpg')
        ->and(Thumbnails::url('docs/manual.pdf', 480))->toEndWith('/storage/docs/manual.pdf')
        ->and(Thumbnails::url('https://cdn.test/a.jpg', 480))->toBe('https://cdn.test/a.jpg')
        ->and(Thumbnails::url(null, 480))->toBeNull()
        ->and(Thumbnails::srcset($path, [480, 960]))->toContain('480w')->toContain('960w');
});

it('makes the thumbnail on first request, as a smaller WebP that is stored for nginx to serve from then on', function () {
    $path = storedPhoto();
    $url = parse_url(Thumbnails::url($path, 480), PHP_URL_PATH);

    $response = $this->get(rawurldecode($url))->assertOk()->assertHeader('Content-Type', 'image/webp');

    Storage::disk('public')->assertExists(Thumbnails::thumbPath($path, 480, Storage::disk('public')->lastModified($path)));

    $size = getimagesizefromstring($response->getContent());
    expect($size[0])->toBe(480)->and($size[1])->toBe(360)->and($size['mime'])->toBe('image/webp');
});

it('never enlarges a small picture', function () {
    $path = storedPhoto('listings/u1/photo.png', 300, 200);

    $response = $this->get(parse_url(Thumbnails::url($path, 960), PHP_URL_PATH))->assertOk();

    expect(getimagesizefromstring($response->getContent())[0])->toBe(300);
});

it('refuses names it never hands out: other widths, a wrong time, path tricks, thumbnails of thumbnails', function () {
    $path = 'designs/k1/cover.jpg';
    storedPhoto($path);
    $mtime = Storage::disk('public')->lastModified($path);

    $this->get("/storage/thumbs/333/{$path}.{$mtime}.webp")->assertNotFound();
    $this->get('/storage/thumbs/480/'.$path.'.'.($mtime + 1).'.webp')->assertNotFound();
    $this->get("/storage/thumbs/480/designs/k1/../k1/cover.jpg.{$mtime}.webp")->assertNotFound();
    $this->get("/storage/thumbs/480/thumbs/480/{$path}.{$mtime}.webp")->assertNotFound();
    $this->get("/storage/thumbs/480/designs/k1/nope.jpg.{$mtime}.webp")->assertNotFound();

    expect(Storage::disk('public')->allFiles('thumbs'))->toBe([]);
});

it('removes the thumbnails with the design', function () {
    $design = Design::factory()->create();
    $path = storedPhoto($design->storageDirectory('Attachments/Display Images/a.jpg'));
    Thumbnails::generate(480, $path.'.'.Storage::disk('public')->lastModified($path).'.webp');
    expect(Storage::disk('public')->allFiles('thumbs'))->not->toBe([]);

    $design->delete();

    expect(Storage::disk('public')->allFiles('thumbs'))->toBe([]);
});

it('shows resized pictures on the browse pages with a bounded number of queries', function () {
    foreach (Design::factory()->count(6)->create(['active' => true]) as $design) {
        $design->update(['card_image' => [storedPhoto($design->storageDirectory('Attachments/Display Images/c.jpg'))]]);
    }

    DB::enableQueryLog();
    $this->get('/designs')->assertOk()->assertSee('/storage/thumbs/480/', false)->assertSee('loading="lazy"', false);
    $designQueries = count(DB::getQueryLog());
    Driver::factory()->count(6)->create(['active' => true]);
    DB::flushQueryLog();
    $this->get('/drivers')->assertOk();
    $driverQueries = count(DB::getQueryLog());

    // Was about 7 queries per design card (ratings) and every design of every driver; now a fixed handful.
    expect($designQueries)->toBeLessThan(20)->and($driverQueries)->toBeLessThan(20);
});

it('sorts drivers by power from the sort menu and ignores unknown sort columns', function () {
    Driver::factory()->create(['active' => true, 'model' => 'Smallpower-X1', 'power' => 10]);
    Driver::factory()->create(['active' => true, 'model' => 'Bigpower-X9', 'power' => 5000]);

    $this->get('/drivers?sort=power_handling&direction=desc')->assertOk()->assertSee('Bigpower-X9');
    expect($this->get('/drivers?sort=power_handling&direction=asc')->assertOk()->getContent())->not->toContain('Bigpower-X9');
    $this->get('/drivers?sort=nope&direction=sideways')->assertOk();
    $this->get('/designs?sort=nope&direction=sideways')->assertOk();
});
