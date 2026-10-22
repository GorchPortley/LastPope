<?php

namespace App\Services\Snapshots;

use Carbon\Carbon;
use Throwable;

/**
 * Renders the design PDF (resources/views/pdf/Design.blade.php) with dompdf.
 *
 * The content is the snapshot's own data (the manifest shape, see DesignSnapshotBuilder::content()), not the live
 * design: a PDF made from version 1.0's manifest shows version 1.0 even after the designer changed the design.
 * Without a licence this is the generic copy that is stored in the snapshot ZIP; with one the footer on every page
 * carries the licensee (see PdfLicense), which is how each download gets its own copy.
 *
 * Images are read from disk and embedded (PdfImages), and the frequency response figure is drawn from the response data
 * the snapshot kept (ResponseChart): dompdf never fetches anything over the network, and neither PHP nor JavaScript
 * inside the HTML is evaluated.
 */
class DesignPdf
{
    public function __construct(
        private readonly PdfImages $images = new PdfImages(),
        private readonly ResponseChart $chart = new ResponseChart(),
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  snapshot content (name, designer, version, summary, components, ...)
     * @return string the PDF file
     */
    public function render(array $data, ?PdfLicense $license = null): string
    {
        $pdf = app('dompdf.wrapper');
        $pdf->setOption([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ]);
        $pdf->setPaper('a4');
        $pdf->loadView('pdf.Design', ['pdf' => $this->viewData($data), 'license' => $license]);

        $title = trim((string) ($data['name'] ?? '')) ?: 'Design';
        $pdf->addInfo(array_filter([
            'Title' => $title.($this->text($data, 'version') !== '' ? ' ('.$this->text($data, 'version').')' : ''),
            'Author' => $this->text($data, 'designer'),
            'Creator' => (string) config('app.name'),
        ], fn ($value) => $value !== ''));

        $pdf->render();
        $this->addPageNumbers($pdf);

        return $pdf->output();
    }

    /** "Page 1 of 3" at the right end of the footer's second line, written by dompdf on every page once the page count is known. */
    private function addPageNumbers($pdf): void
    {
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');

        // A4 is 595.28 x 841.89 pt; the footer sits 18 mm above the bottom edge, inside the 18 mm side margins.
        $size = 7;
        $text = 'Page {PAGE_NUM} of {PAGE_COUNT}';
        $width = $dompdf->getFontMetrics()->getTextWidth('Page 99 of 99', $font, $size);
        $canvas->page_text($canvas->get_width() - 51.02 - $width, $canvas->get_height() - 51.02 + 6, $text, $font, $size, [0.42, 0.45, 0.50]);
    }

    /**
     * The content as the view wants it: plain strings and arrays, every rich text converted to HTML with its images
     * embedded, the response figure as a data URI. Missing keys (an old or hand-made snapshot) simply leave their section out.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function viewData(array $data): array
    {
        $components = collect((array) ($data['components'] ?? []))
            ->filter(fn ($component) => is_array($component))
            ->map(fn (array $component) => [
                'position' => strtoupper($this->text($component, 'position')),
                'driver' => $this->text($component, 'driver') ?: 'Driver',
                'quantity' => max(1, (int) ($component['quantity'] ?? 1)),
                'range' => $this->range($component['low_frequency'] ?? null, $component['high_frequency'] ?? null),
                'air_volume' => $this->number($component['air_volume'] ?? null) ?? '-',
                'html' => $this->html($component['description'] ?? null),
            ])
            ->values();

        $money = (int) ($data['build_cost_cents'] ?? 0);

        return [
            'name' => trim((string) ($data['name'] ?? '')) ?: 'Design',
            'designer' => $this->text($data, 'designer'),
            'tag' => $this->text($data, 'tag'),
            'version' => $this->text($data, 'version'),
            'date' => $this->date($data['created_at'] ?? null),
            'specs' => array_filter([
                'Category' => $this->text($data, 'category'),
                'Power handling' => ($power = $this->number($data['power'] ?? null)) !== null ? $power.' W' : '',
                'Impedance' => ($impedance = $this->number($data['impedance'] ?? null)) !== null ? $impedance.' Ω' : '',
                'Estimated build cost' => $money > 0 ? money($money) : '',
                'Drivers' => $components->isNotEmpty() ? (string) $components->sum('quantity') : '',
            ], fn ($value) => $value !== ''),
            'images' => $this->images->embedAll((array) ($data['images'] ?? []), 4),
            'responseChart' => $this->chart->dataUri(is_array($data['frequency_response'] ?? null) ? $data['frequency_response'] : null),
            'summaryHtml' => $this->html($data['summary'] ?? null),
            'descriptionHtml' => $this->html($data['description'] ?? null),
            'bom' => $this->pairs($data['bom'] ?? null),
            'components' => $components->all(),
        ];
    }

    /** Rich text (editor JSON or HTML) to HTML, with the images embedded; empty when there is nothing to show. */
    private function html(mixed $value): string
    {
        if (blank($value)) {
            return '';
        }

        try {
            $html = (string) tiptap_converter()->asHtml($value);
        } catch (Throwable) {
            return '';
        }

        return trim(strip_tags($html, '<img><table><hr>')) === '' ? '' : $this->images->inlineHtml($html);
    }

    /**
     * A key/value list (the bill of materials) as label => text, blank entries dropped.
     *
     * @return array<string, string>
     */
    private function pairs(mixed $value): array
    {
        $pairs = [];

        foreach ((array) $value as $key => $item) {
            $text = is_scalar($item) ? trim((string) $item) : '';

            if (is_string($key) && trim($key) !== '' && $text !== '') {
                $pairs[trim($key)] = $text;
            }
        }

        return $pairs;
    }

    private function range(mixed $low, mixed $high): string
    {
        $low = $this->number($low);
        $high = $this->number($high);

        return match (true) {
            $low !== null && $high !== null => $low.' – '.$high.' Hz',
            $low !== null => 'from '.$low.' Hz',
            $high !== null => 'up to '.$high.' Hz',
            default => '-',
        };
    }

    /** "80.00" => "80", "2500.50" => "2,500.5"; null for empty or non-numeric values. */
    private function number(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');
    }

    private function date(mixed $value): string
    {
        if (blank($value)) {
            return '';
        }

        try {
            return Carbon::parse((string) $value)->format('M j, Y');
        } catch (Throwable) {
            return '';
        }
    }

    /** @param array<string, mixed> $source */
    private function text(array $source, string $key): string
    {
        $value = $source[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
