<?php

namespace App\Services\Marketplace;

use App\Exceptions\Marketplace\SupplierFeedException;
use App\Models\Listing;
use App\Models\Supplier;
use App\Services\Marketplace\Data\FeedSyncResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reads the stock feeds of the suppliers (docs/PLAN.md 8.4): a CSV over HTTP (feed_url) or a file an admin uploaded to the
 * private disk (feed_file_path). Rows are matched to the supplier's listings by supplier_sku (case and spaces do not
 * matter); the quantity becomes listings.supplier_stock, a mapped cost column also updates cost_cents. Listings that are
 * not in the feed are left alone, nothing is ever deleted, and a feed that cannot be read changes no stock - the error is
 * stored on the supplier (feed_last_error) and shown to the admins.
 *
 * Tolerant of what spreadsheets produce: a UTF-8 (or UTF-16) byte order mark, Windows-1252 text, , ; tab or | as
 * delimiter (detected), column names in any case with stray spaces, "1,200" and "12.0" quantities and "$1,299.00" or "12,50" costs.
 */
class SupplierFeedSync
{
    /**
     * Sync every active supplier that has a feed.
     *
     * @return Collection<int, FeedSyncResult>
     */
    public function syncAll(): Collection
    {
        return Supplier::query()->active()->withFeed()->orderBy('id')->get()->map(fn (Supplier $supplier) => $this->sync($supplier));
    }

    /** Sync one supplier. Never throws for feed problems: they come back as a failed result and are recorded on the supplier. */
    public function sync(Supplier $supplier): FeedSyncResult
    {
        try {
            $parsed = $this->parse(
                $this->fetch($supplier),
                (string) ($supplier->feed_sku_column ?: 'sku'),
                (string) ($supplier->feed_quantity_column ?: 'quantity'),
                filled($supplier->feed_cost_column) ? (string) $supplier->feed_cost_column : null,
            );

            $result = $this->apply($supplier, $parsed['rows'], $parsed['skipped']);
        } catch (SupplierFeedException $e) {
            $supplier->forceFill(['feed_last_error' => $e->getMessage()])->saveQuietly();
            Log::warning('Supplier stock feed failed.', ['supplier_id' => $supplier->getKey(), 'error' => $e->getMessage()]);

            return new FeedSyncResult($supplier, false, error: $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            $message = 'Unexpected error: '.$e->getMessage();
            $supplier->forceFill(['feed_last_error' => $message])->saveQuietly();

            return new FeedSyncResult($supplier, false, error: $message);
        }

        $supplier->forceFill(['feed_last_synced_at' => now(), 'feed_last_error' => null])->saveQuietly();
        Log::info('Supplier stock feed synced.', [
            'supplier_id' => $supplier->getKey(), 'rows' => $result->rows, 'matched' => $result->matched,
            'updated' => $result->updated, 'unmatched' => $result->unmatched, 'skipped' => $result->skipped,
        ]);

        return $result;
    }

    /**
     * A CSV the supplier (or an admin) can fill in: the configured SKU, quantity and - when mapped - cost column headers,
     * with a row per SKU of the supplier's listings (quantity and cost left empty: such rows are skipped by a sync) or one
     * example row when the supplier has no listings with a SKU yet. For the admin's "Download feed template" action.
     */
    public function templateCsv(Supplier $supplier): string
    {
        $skuColumn = (string) ($supplier->feed_sku_column ?: 'sku');
        $quantityColumn = (string) ($supplier->feed_quantity_column ?: 'quantity');
        $costColumn = filled($supplier->feed_cost_column) ? (string) $supplier->feed_cost_column : null;

        $skus = $supplier->listings()->whereNotNull('supplier_sku')->where('supplier_sku', '!=', '')->orderBy('id')->pluck('supplier_sku')->unique()->values();

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_values(array_filter([$skuColumn, $quantityColumn, $costColumn])), escape: '');

        if ($skus->isEmpty()) {
            fputcsv($handle, array_values(array_filter(['EXAMPLE-SKU-001', '10', $costColumn !== null ? '12.50' : null], fn ($cell) => $cell !== null)), escape: '');
        }

        foreach ($skus as $sku) {
            fputcsv($handle, $costColumn !== null ? [$sku, '', ''] : [$sku, ''], escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    // --- Reading the feed ------------------------------------------------------------------------------------------

    /** @throws SupplierFeedException */
    private function fetch(Supplier $supplier): string
    {
        $maxBytes = max(1, (int) config('marketplace.supplier_feed_max_kb', 5120)) * 1024;

        if (filled($supplier->feed_url)) {
            $url = trim((string) $supplier->feed_url);

            if (! preg_match('#^https?://#i', $url)) {
                throw new SupplierFeedException('The feed URL must start with http:// or https://.');
            }

            // An internal address is never a supplier (keeps a mistyped or malicious URL away from the server's own network).
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '' || $host === 'localhost' || (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) && ! filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
                throw new SupplierFeedException('The feed URL must point to a public web address.');
            }

            try {
                $response = Http::timeout(max(1, (int) config('marketplace.supplier_feed_timeout_seconds', 20)))
                    ->connectTimeout(10)
                    ->withHeaders(['Accept' => 'text/csv, text/plain, */*'])
                    ->get($url);
            } catch (ConnectionException $e) {
                throw new SupplierFeedException('The feed could not be downloaded: '.$e->getMessage());
            }

            if (! $response->successful()) {
                throw new SupplierFeedException('The feed returned HTTP '.$response->status().'.');
            }

            $body = $response->body();
        } elseif (filled($supplier->feed_file_path)) {
            $disk = Storage::disk((string) config('marketplace.private_disk', 'private'));

            if (! $disk->exists($supplier->feed_file_path)) {
                throw new SupplierFeedException('The uploaded feed file is missing. Upload it again.');
            }

            $body = (string) $disk->get($supplier->feed_file_path);
        } else {
            throw new SupplierFeedException('This supplier has no stock feed (no URL and no uploaded file).');
        }

        if (strlen($body) > $maxBytes) {
            throw new SupplierFeedException('The feed is larger than '.intdiv($maxBytes, 1024).' KB.');
        }

        return $body;
    }

    /**
     * Turn CSV text into SKU => [quantity, cost]. Public for tests and for a "check this file" action.
     *
     * @return array{rows: array<string, array{sku: string, quantity: int, cost_cents: ?int}>, skipped: int}
     *
     * @throws SupplierFeedException  empty file, or a mapped column is not in the header
     */
    public function parse(string $csv, string $skuColumn = 'sku', string $quantityColumn = 'quantity', ?string $costColumn = null): array
    {
        $csv = $this->toUtf8($csv);

        if (trim($csv) === '') {
            throw new SupplierFeedException('The feed is empty.');
        }

        $delimiter = $this->detectDelimiter($csv);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $header = null;
        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if ($this->isBlank($row)) {
                continue;
            }
            $header = array_map(fn ($name) => $this->normalizeName((string) $name), $row);
            break;
        }

        if ($header === null) {
            fclose($stream);
            throw new SupplierFeedException('The feed has no header row.');
        }

        $columns = [];
        foreach (['SKU' => $skuColumn, 'quantity' => $quantityColumn] + ($costColumn !== null ? ['cost' => $costColumn] : []) as $label => $name) {
            $index = array_search($this->normalizeName($name), $header, true);

            if ($index === false) {
                fclose($stream);
                throw new SupplierFeedException("The {$label} column \"{$name}\" is not in the feed. Columns found: ".implode(', ', array_filter($header, fn ($h) => $h !== '')).'.');
            }

            $columns[$label] = $index;
        }

        $rows = [];
        $skipped = 0;

        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if ($this->isBlank($row)) {
                continue;
            }

            $sku = trim((string) ($row[$columns['SKU']] ?? ''));
            $quantity = $this->parseQuantity((string) ($row[$columns['quantity']] ?? ''));

            if ($sku === '' || $quantity === null) {
                $skipped++;

                continue;
            }

            $rows[$this->normalizeSku($sku)] = [
                'sku' => $sku,
                'quantity' => $quantity,
                'cost_cents' => isset($columns['cost']) ? $this->parseMoney((string) ($row[$columns['cost']] ?? '')) : null,
            ];
        }

        fclose($stream);

        if ($rows === []) {
            throw new SupplierFeedException('The feed has no usable rows (a SKU and a quantity).');
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /** @param  array<string, array{sku: string, quantity: int, cost_cents: ?int}>  $rows */
    private function apply(Supplier $supplier, array $rows, int $skipped): FeedSyncResult
    {
        $matched = $updated = $unmatched = 0;

        $supplier->listings()->whereNotNull('supplier_sku')->where('supplier_sku', '!=', '')->orderBy('id')->each(function (Listing $listing) use ($rows, &$matched, &$updated, &$unmatched) {
            $row = $rows[$this->normalizeSku((string) $listing->supplier_sku)] ?? null;

            if ($row === null) {
                $unmatched++;

                return;
            }

            $matched++;
            $listing->supplier_stock = $row['quantity'];
            $listing->supplier_stock_synced_at = now();

            if ($row['cost_cents'] !== null) {
                $listing->cost_cents = $row['cost_cents'];
            }

            if ($listing->isDirty(['supplier_stock', 'cost_cents'])) {
                $updated++;
            }

            $listing->saveQuietly();
        });

        return new FeedSyncResult($supplier, true, rows: count($rows), matched: $matched, updated: $updated, unmatched: $unmatched, skipped: $skipped);
    }

    // --- Parsing helpers -------------------------------------------------------------------------------------------

    private function toUtf8(string $csv): string
    {
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            return substr($csv, 3);
        }

        if (str_starts_with($csv, "\xFF\xFE")) {
            return mb_convert_encoding(substr($csv, 2), 'UTF-8', 'UTF-16LE');
        }

        if (str_starts_with($csv, "\xFE\xFF")) {
            return mb_convert_encoding(substr($csv, 2), 'UTF-8', 'UTF-16BE');
        }

        return mb_check_encoding($csv, 'UTF-8') ? $csv : mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
    }

    /** The delimiter that splits the header line into the most fields (outside quotes): comma, semicolon, tab or pipe. */
    private function detectDelimiter(string $csv): string
    {
        $first = '';
        foreach (preg_split('/\r\n|\n|\r/', $csv) as $line) {
            if (trim($line) !== '') {
                $first = $line;
                break;
            }
        }

        $best = ',';
        $bestCount = 0;

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = count(str_getcsv($first, $candidate, '"', '')) - 1;

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private function isBlank(array $row): bool
    {
        return count($row) === 1 && ($row[0] === null || trim((string) $row[0]) === '');
    }

    /** "  Supplier  SKU " -> "supplier sku" */
    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $name))));
    }

    private function normalizeSku(string $sku): string
    {
        return mb_strtolower(trim($sku));
    }

    /** "12", " 12 ", "12.0", "1,200" -> int (never negative); anything else -> null. */
    private function parseQuantity(string $value): ?int
    {
        $value = str_replace([' ', "\xC2\xA0"], '', trim($value));

        if (preg_match('/^\+?\d{1,3}([,.]\d{3})+$/', $value)) {
            return (int) preg_replace('/[,.]/', '', $value);
        }

        if (preg_match('/^[+-]?\d+([.,]\d+)?$/', $value)) {
            return max(0, (int) floor((float) str_replace(',', '.', $value)));
        }

        return null;
    }

    /** "12.50", "$1,299.00", "12,50", "1.299,00" (dollars) -> cents; empty or unreadable -> null. */
    private function parseMoney(string $value): ?int
    {
        $value = preg_replace('/[^\d,.\-]/', '', $value) ?? '';

        if ($value === '' || ! preg_match('/\d/', $value)) {
            return null;
        }

        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');

        if ($comma !== false && $dot !== false) {
            $value = $comma > $dot ? str_replace(',', '.', str_replace('.', '', $value)) : str_replace(',', '', $value);
        } elseif ($comma !== false) {
            $value = preg_match('/,\d{1,2}$/', $value) ? str_replace(',', '.', $value) : str_replace(',', '', $value);
        }

        $cents = (int) round((float) $value * 100);

        return $cents < 0 ? null : $cents;
    }
}
