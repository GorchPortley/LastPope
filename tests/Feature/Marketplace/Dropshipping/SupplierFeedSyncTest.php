<?php

use App\Console\Commands\MarketplaceSyncSupplierStock;
use App\Exceptions\Marketplace\SupplierFeedException;
use App\Models\Listing;
use App\Models\Supplier;
use App\Services\Marketplace\SupplierFeedSync;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
| R3.4: marketplace:sync-supplier-stock reads each supplier's feed (CSV over HTTP, or an uploaded file) into
| listings.supplier_stock (and cost_cents when a cost column is mapped), tolerates what spreadsheets produce, records
| errors on the supplier and never deletes anything.
*/

beforeEach(function () {
    $GLOBALS['feed_answer'] = ['', 200];
    Http::preventStrayRequests();
    Http::fake(['feeds.example.test/*' => fn () => $GLOBALS['feed_answer'] === 'timeout'
        ? throw new ConnectionException('cURL error 28: Operation timed out')
        : Http::response(...$GLOBALS['feed_answer'])]);
    Storage::fake('private');
    $this->sync = app(SupplierFeedSync::class);
    $this->supplier = supplier(['feed_url' => 'https://feeds.example.test/stock.csv', 'feed_cost_column' => 'cost']);
    $this->a = dropshipListing($this->supplier, ['supplier_sku' => 'AB-100', 'cost_cents' => 5000, 'supplier_stock' => 1]);
    $this->b = dropshipListing($this->supplier, ['supplier_sku' => 'CD-200', 'cost_cents' => 6000, 'supplier_stock' => 2]);
    $this->c = dropshipListing($this->supplier, ['supplier_sku' => 'EF-300', 'cost_cents' => 7000, 'supplier_stock' => 3]);
});

/** What the fake feed server answers from now on (a later call replaces the answer). */
function feed(string $body, int $status = 200): void
{
    $GLOBALS['feed_answer'] = [$body, $status];
}

it('updates stock and cost from a feed over HTTP and records the sync', function () {
    feed("sku,quantity,cost\nAB-100,12,49.50\nCD-200,0,60.00\nZZ-999,5,1.00\n");

    $result = $this->sync->sync($this->supplier);

    expect($result->ok)->toBeTrue()
        ->and([$result->rows, $result->matched, $result->updated, $result->unmatched, $result->skipped])->toBe([3, 2, 2, 1, 0])
        ->and($this->a->fresh()->supplier_stock)->toBe(12)
        ->and($this->a->fresh()->cost_cents)->toBe(4950)
        ->and($this->a->fresh()->supplier_stock_synced_at)->not->toBeNull()
        ->and($this->b->fresh()->supplier_stock)->toBe(0)
        // not in the feed: left exactly as it was, and nothing was deleted
        ->and($this->c->fresh()->supplier_stock)->toBe(3)
        ->and($this->c->fresh()->cost_cents)->toBe(7000)
        ->and(Listing::where('supplier_id', $this->supplier->id)->count())->toBe(3);

    $this->supplier->refresh();
    expect($this->supplier->feed_last_synced_at)->not->toBeNull()->and($this->supplier->feed_last_error)->toBeNull();
    Http::assertSentCount(1);
});

it('does not touch the cost when the feed has no cost column mapped', function () {
    $this->supplier->update(['feed_cost_column' => null]);
    feed("sku,quantity,cost\nAB-100,4,1.00\n");

    $this->sync->sync($this->supplier);

    expect($this->a->fresh()->supplier_stock)->toBe(4)->and($this->a->fresh()->cost_cents)->toBe(5000);
});

it('reads spreadsheet exports: byte order mark, semicolons, odd header case and spaces, CRLF', function () {
    feed("\xEF\xBB\xBF  SKU ;  Quantity ; COST \r\n ab-100 ;7;\"49,90\"\r\nCD-200;\"1,200\";\"$1.234,56\"\r\n\r\nEF-300;3.0;\r\n");

    $result = $this->sync->sync($this->supplier);

    expect($result->ok)->toBeTrue()
        ->and($this->a->fresh()->supplier_stock)->toBe(7)     // SKU matched ignoring case and spaces
        ->and($this->a->fresh()->cost_cents)->toBe(4990)      // decimal comma
        ->and($this->b->fresh()->supplier_stock)->toBe(1200)  // thousands separator
        ->and($this->b->fresh()->cost_cents)->toBe(123456)    // 1.234,56
        ->and($this->c->fresh()->supplier_stock)->toBe(3)
        ->and($this->c->fresh()->cost_cents)->toBe(7000);     // empty cost: unchanged
});

it('detects tab and pipe delimiters, quoted fields and other column names', function () {
    $this->supplier->update(['feed_sku_column' => 'Item Number', 'feed_quantity_column' => 'On Hand', 'feed_cost_column' => 'Dealer Price']);
    feed("Item Number\tDescription\tOn Hand\tDealer Price\nAB-100\t\"Tweeter, 1 inch\"\t8\t\$45.00\n");

    expect($this->sync->sync($this->supplier)->ok)->toBeTrue()
        ->and($this->a->fresh()->supplier_stock)->toBe(8)
        ->and($this->a->fresh()->cost_cents)->toBe(4500);

    feed("item number|on hand|dealer price\nCD-200|5|61.5\n");
    expect($this->sync->sync($this->supplier)->ok)->toBeTrue()
        ->and($this->b->fresh()->supplier_stock)->toBe(5)
        ->and($this->b->fresh()->cost_cents)->toBe(6150);
});

it('reads Windows-1252 text and UTF-16 files', function () {
    feed("sku,quantity,cost,description\nAB-100,3,50.00,Caf\xE9 tweeter\n");
    expect($this->sync->sync($this->supplier)->ok)->toBeTrue()->and($this->a->fresh()->supplier_stock)->toBe(3);

    $utf16 = "\xFF\xFE".mb_convert_encoding("sku;quantity;cost\nCD-200;9;60.00\n", 'UTF-16LE', 'UTF-8');
    feed($utf16);
    expect($this->sync->sync($this->supplier)->ok)->toBeTrue()->and($this->b->fresh()->supplier_stock)->toBe(9);
});

it('skips rows without a sku or a readable quantity and counts them', function () {
    feed("sku,quantity,cost\nAB-100,5,\n,9,1.00\nCD-200,many,1.00\nEF-300,-4,1.00\n");

    $result = $this->sync->sync($this->supplier);

    expect($result->ok)->toBeTrue()
        ->and($result->skipped)->toBe(2)
        ->and($this->a->fresh()->supplier_stock)->toBe(5)
        ->and($this->b->fresh()->supplier_stock)->toBe(2) // "many": untouched
        ->and($this->c->fresh()->supplier_stock)->toBe(0); // negative counts as none
});

it('reads an uploaded file from the private disk when there is no URL', function () {
    Storage::disk('private')->put('suppliers/feed.csv', "sku,quantity,cost\nEF-300,21,70.25\n");
    $supplier = supplier(['feed_url' => null, 'feed_file_path' => 'suppliers/feed.csv', 'feed_cost_column' => 'cost']);
    $listing = dropshipListing($supplier, ['supplier_sku' => 'EF-300', 'cost_cents' => 100]);

    $result = $this->sync->sync($supplier);

    expect($result->ok)->toBeTrue()
        ->and($listing->fresh()->supplier_stock)->toBe(21)
        ->and($listing->fresh()->cost_cents)->toBe(7025);
    Http::assertNothingSent();
});

it('records the error and changes no stock when the feed cannot be read', function (string $body, int $status, string $message) {
    feed($body, $status);
    $before = Listing::where('supplier_id', $this->supplier->id)->pluck('supplier_stock', 'id')->all();

    $result = $this->sync->sync($this->supplier);

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain($message)
        ->and(Listing::where('supplier_id', $this->supplier->id)->pluck('supplier_stock', 'id')->all())->toBe($before);

    $this->supplier->refresh();
    expect($this->supplier->feed_last_error)->toContain($message)->and($this->supplier->feed_last_synced_at)->toBeNull();
})->with([
    'server error' => ['Oops', 500, 'HTTP 500'],
    'not found' => ['', 404, 'HTTP 404'],
    'empty file' => ['', 200, 'empty'],
    'sku column missing' => ["code,quantity\nAB-100,3\n", 200, 'SKU column "sku" is not in the feed. Columns found: code, quantity'],
    'quantity column missing' => ["sku,stock\nAB-100,3\n", 200, 'quantity column "quantity"'],
    'cost column missing' => ["sku,quantity\nAB-100,3\n", 200, 'cost column "cost"'],
    'no usable rows' => ["sku,quantity,cost\n,,\nAB-100,lots,1\n", 200, 'no usable rows'],
]);

it('clears an old error after a good sync', function () {
    feed('x', 500);
    $this->sync->sync($this->supplier);
    expect($this->supplier->fresh()->feed_last_error)->not->toBeNull();

    feed("sku,quantity,cost\nAB-100,1,1.00\n");
    $this->sync->sync($this->supplier->fresh());

    expect($this->supplier->fresh()->feed_last_error)->toBeNull()->and($this->supplier->fresh()->feed_last_synced_at)->not->toBeNull();
});

it('survives a connection failure and a timeout', function () {
    $GLOBALS['feed_answer'] = 'timeout';

    $result = $this->sync->sync($this->supplier);

    expect($result->ok)->toBeFalse()->and($result->error)->toContain('could not be downloaded')->toContain('timed out')
        ->and($this->supplier->fresh()->feed_last_error)->toContain('timed out');
});

it('asks for CSV, never follows an internal address and refuses a huge file', function () {
    feed("sku,quantity,cost\nAB-100,1,1.00\n");
    $this->sync->sync($this->supplier);
    Http::assertSent(fn ($request) => $request->url() === 'https://feeds.example.test/stock.csv' && ($request->header('Accept')[0] ?? '') !== '');

    foreach (['http://localhost/feed.csv', 'http://127.0.0.1/feed.csv', 'http://169.254.169.254/latest', 'http://192.168.1.5/f.csv', 'ftp://example.test/f.csv', 'feed.csv'] as $url) {
        $this->supplier->update(['feed_url' => $url]);
        $result = $this->sync->sync($this->supplier->fresh());
        expect($result->ok)->toBeFalse($url);
    }
    Http::assertSentCount(1);

    $this->supplier->update(['feed_url' => 'https://feeds.example.test/big.csv']);
    config(['marketplace.supplier_feed_max_kb' => 1]);
    feed("sku,quantity,cost\n".str_repeat("AB-100,1,1.00\n", 300));
    expect($this->sync->sync($this->supplier->fresh())->error)->toContain('larger than 1 KB');
});

it('parses CSV text on its own', function () {
    $parsed = $this->sync->parse("Sku;Qty;Price\nA1;4;9,99\nB2;x;1\n", 'sku', 'qty', 'price');

    expect($parsed['rows'])->toBe(['a1' => ['sku' => 'A1', 'quantity' => 4, 'cost_cents' => 999]])
        ->and($parsed['skipped'])->toBe(1);
    expect(fn () => $this->sync->parse('', 'sku', 'quantity'))->toThrow(SupplierFeedException::class);
});

it('syncs every active supplier with a feed and skips the others', function () {
    $inactive = supplier(['feed_url' => 'https://feeds.example.test/inactive.csv', 'active' => false]);
    $noFeed = supplier();
    feed("sku,quantity,cost\nAB-100,6,50.00\n");

    $results = $this->sync->syncAll();
    $names = $results->map(fn ($r) => $r->supplier->id)->all();

    expect($names)->toContain($this->supplier->id)->not->toContain($inactive->id, $noFeed->id)
        ->and($this->a->fresh()->supplier_stock)->toBe(6);
});

it('runs from the command line, per supplier, and reports failures with an exit code', function () {
    feed("sku,quantity,cost\nAB-100,15,50.00\n");

    expect(Artisan::call('marketplace:sync-supplier-stock', ['--supplier' => $this->supplier->id]))->toBe(0)
        ->and(Artisan::output())->toContain($this->supplier->name)->toContain('1 feed rows')
        ->and($this->a->fresh()->supplier_stock)->toBe(15);

    expect(Artisan::call('marketplace:sync-supplier-stock', ['--supplier' => 999999]))->toBe(1);

    feed('', 500);
    expect(Artisan::call('marketplace:sync-supplier-stock'))->toBe(1)
        ->and(Artisan::output())->toContain('HTTP 500');
});

it('logs a summary of every sync', function () {
    Log::spy();
    feed("sku,quantity,cost\nAB-100,2,50.00\n");

    $this->sync->sync($this->supplier);

    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => str_contains($message, 'feed synced') && ($context['updated'] ?? null) === 1 && ($context['matched'] ?? null) === 1)->once();
});

it('runs every hour', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events->contains(fn ($event) => str_contains($event->command, 'marketplace:sync-supplier-stock') && $event->expression === '0 * * * *'))->toBeTrue()
        ->and(class_exists(MarketplaceSyncSupplierStock::class))->toBeTrue();
});
