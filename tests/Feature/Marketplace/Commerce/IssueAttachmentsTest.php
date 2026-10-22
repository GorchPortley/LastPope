<?php

use App\Enums\IssueReason;
use App\Models\OrderIssue;
use App\Models\OrderIssueMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| R2.2: photos on a problem report. At most 5 images of at most 8 MB per message, kept on the private disk, shown to
| the buyer, the seller and admins only through an authorised route.
*/

beforeEach(function () {
    $this->issuesBefore = OrderIssue::count(); // the demo data has problem reports of its own
    Storage::fake('private');
    Storage::fake('public');
    config(['marketplace.physical_payout_trigger' => 'shipped_delay']);
});

it('stores up to 5 photos on the private disk when the problem is reported', function () {
    $x = reportableOrder();
    $photos = collect(range(1, 5))->map(fn ($i) => issuePhoto("damage-{$i}.jpg"))->all();

    $issue = reportProblem($x, IssueReason::DAMAGED, 'The cone is dented, see the pictures.', $photos);

    $files = $issue->messages->first()->files();
    expect($files)->toHaveCount(5);

    foreach ($files as $i => $file) {
        expect($file['path'])->toStartWith('order-issues/'.$issue->id.'/')
            ->and($file['name'])->toBe('damage-'.($i + 1).'.jpg')
            ->and($file['mime'])->toBe('image/jpeg')
            ->and($file['size'])->toBeGreaterThan(0);
        Storage::disk('private')->assertExists($file['path']);
        Storage::disk('public')->assertMissing($file['path']);
    }
});

it('refuses a sixth photo and creates nothing', function () {
    $x = reportableOrder();
    $photos = collect(range(1, 6))->map(fn ($i) => issuePhoto("p{$i}.jpg"))->all();

    expect(fn () => reportProblem($x, IssueReason::DAMAGED, 'Six pictures of the same dent.', $photos))->toThrow(ValidationException::class, 'up to 5 photos');

    expect((OrderIssue::count() - $this->issuesBefore))->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('accepts 8 MB per photo and refuses more', function () {
    $x = reportableOrder();

    $issue = reportProblem($x, IssueReason::DAMAGED, 'One big picture of the dent.', [issuePhoto('big.jpg', 8192)]);
    expect($issue->messages->first()->files())->toHaveCount(1);
    issues()->withdraw($x['buyer'], $issue);

    expect(fn () => reportProblem($x, IssueReason::DAMAGED, 'A picture that is too large.', [issuePhoto('huge.jpg', 8193)]))
        ->toThrow(ValidationException::class, '8 MB');
    expect((OrderIssue::count() - $this->issuesBefore))->toBe(1);
});

it('accepts only pictures: no documents, scripts or SVG', function (string $name, string $mime) {
    $x = reportableOrder();
    $file = UploadedFile::fake()->create($name, 20, $mime);

    expect(fn () => reportProblem($x, IssueReason::OTHER, 'Attaching something that is no picture.', [$file]))->toThrow(ValidationException::class)
        ->and((OrderIssue::count() - $this->issuesBefore))->toBe(0);
})->with([
    'pdf' => ['invoice.pdf', 'application/pdf'],
    'svg' => ['x.svg', 'image/svg+xml'],
    'php' => ['shell.php', 'application/x-php'],
    'text pretending' => ['photo.jpg', 'text/plain'],
]);

it('takes photos with later messages too, also from the seller', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    $message = issues()->reply($x['seller'], $issue, 'It left my shop like this.', [issuePhoto('packed.png')]);

    expect($message->files())->toHaveCount(1)
        ->and($message->files()[0]['name'])->toBe('packed.png')
        ->and($message->files()[0]['path'])->toStartWith('order-issues/'.$issue->id.'/');

    expect(fn () => issues()->reply($x['seller'], $issue, 'Too many.', collect(range(1, 6))->map(fn () => issuePhoto())->all()))->toThrow(ValidationException::class);
});

it('serves a photo to the buyer, the seller and admins, privately', function () {
    $x = reportableOrder();
    $issue = reportProblem($x, IssueReason::DAMAGED, 'See the picture.', [issuePhoto('dent.jpg')]);
    $message = $issue->messages->first();
    $url = route('dashboard.issues.attachment', ['orderIssue' => $issue->id, 'message' => $message->id, 'index' => 0]);

    foreach ([$x['buyer'], $x['seller'], adminUser()] as $allowed) {
        $this->actingAs($allowed)->get($url)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    expect($url)->not->toContain('storage');
});

it('keeps everybody else out: 403, and guests are sent to log in', function () {
    $x = reportableOrder();
    $issue = reportProblem($x, IssueReason::DAMAGED, 'See the picture.', [issuePhoto('dent.jpg')]);
    $message = $issue->messages->first();
    $url = route('dashboard.issues.attachment', ['orderIssue' => $issue->id, 'message' => $message->id, 'index' => 0]);

    $this->get($url)->assertRedirectContains('login');

    foreach ([User::factory()->create(), onboardedSeller()] as $stranger) {
        $this->actingAs($stranger)->get($url)->assertForbidden();
    }
});

it('answers 404 for a missing file, a wrong index or a message of another issue', function () {
    $x = reportableOrder();
    $issue = reportProblem($x, IssueReason::DAMAGED, 'See the picture.', [issuePhoto('dent.jpg')]);
    $message = $issue->messages->first();
    $other = reportableOrder();
    $otherIssue = reportProblem($other, IssueReason::DAMAGED, 'Another one.', [issuePhoto('other.jpg')]);

    $this->actingAs($x['buyer']);
    $this->get(route('dashboard.issues.attachment', ['orderIssue' => $issue->id, 'message' => $message->id, 'index' => 3]))->assertNotFound();
    $this->get(route('dashboard.issues.attachment', ['orderIssue' => $issue->id, 'message' => $otherIssue->messages->first()->id, 'index' => 0]))->assertNotFound();
    $this->get(route('dashboard.issues.attachment', ['orderIssue' => $issue->id, 'message' => 99999, 'index' => 0]))->assertNotFound();

    Storage::disk('private')->delete($message->files()[0]['path']);
    $this->get(route('dashboard.issues.attachment', ['orderIssue' => $issue->id, 'message' => $message->id, 'index' => 0]))->assertNotFound();

    // you cannot read somebody else's photo by putting your own issue id in the url
    $this->get(route('dashboard.issues.attachment', ['orderIssue' => $otherIssue->id, 'message' => $otherIssue->messages->first()->id, 'index' => 0]))->assertForbidden();
});

it('hides the attachments of internal notes from the buyer and the seller', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    $admin = adminUser();
    Storage::disk('private')->put('order-issues/'.$issue->id.'/evidence.jpg', 'x');
    $note = OrderIssueMessage::factory()->internal()->create([
        'order_issue_id' => $issue->id, 'author_id' => $admin->id, 'body' => 'evidence',
        'attachments' => [['path' => 'order-issues/'.$issue->id.'/evidence.jpg', 'name' => 'evidence.jpg', 'size' => 1, 'mime' => 'image/jpeg']],
    ]);
    $url = route('dashboard.issues.attachment', ['orderIssue' => $issue->id, 'message' => $note->id, 'index' => 0]);

    $this->actingAs($x['buyer'])->get($url)->assertNotFound();
    $this->actingAs($x['seller'])->get($url)->assertNotFound();
    $this->actingAs($admin)->get($url)->assertOk();
});

it('serves anything but a safe image as a download, never inline', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    Storage::disk('private')->put('order-issues/'.$issue->id.'/page.html', '<script>alert(1)</script>');
    $message = OrderIssueMessage::factory()->create([
        'order_issue_id' => $issue->id, 'author_id' => $x['buyer']->id, 'body' => 'odd file',
        'attachments' => [['path' => 'order-issues/'.$issue->id.'/page.html', 'name' => 'page.html', 'size' => 25, 'mime' => 'text/html']],
    ]);

    $response = $this->actingAs($x['buyer'])->get(route('dashboard.issues.attachment', ['orderIssue' => $issue->id, 'message' => $message->id, 'index' => 0]))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/octet-stream')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});
