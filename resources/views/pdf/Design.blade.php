{{--
    The design PDF in a snapshot ZIP (App\Services\Snapshots\DesignPdf). dompdf renders it (no flexbox, no grid, no
    remote images: layout is tables and blocks, images are data URIs), in the default site palette (App\Support\BrandColors).

    $pdf     normalised content (see DesignPdf::viewData): name, designer, tag, version, date, specs, images, summaryHtml,
             descriptionHtml, components (with their own specs and html), bom
    $license App\Services\Snapshots\PdfLicense or null (the generic copy kept with the snapshot: no name in the footer)
--}}
@php
    use App\Support\BrandColors as C;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $pdf['name'] }}</title>
    <style>
        @page {
            margin: 18mm 18mm 26mm 18mm;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 8.5pt;
            line-height: 1.5;
            color: {{ C::gray(800) }};
            margin: 0;
        }

        p { margin: 0 0 6pt 0; }
        a { color: {{ C::brand(700) }}; text-decoration: none; }
        .muted { color: {{ C::gray(500) }}; }

        /* Header band: full bleed over the page margins on the first page */
        .band {
            background-color: {{ C::brand(950) }};
            color: #ffffff;
            margin: -18mm -18mm 0 -18mm;
            padding: 11mm 18mm 9mm 18mm;
        }
        .band table { width: 100%; border-collapse: collapse; }
        .band td { padding: 0; vertical-align: middle; }
        .band .logo { height: 8mm; }
        .band .kind { text-align: right; font-size: 7.5pt; letter-spacing: 1.5pt; text-transform: uppercase; color: {{ C::accent(200) }}; }
        .band h1 { font-size: 22pt; line-height: 1.2; font-weight: bold; margin: 9mm 0 0 0; color: #ffffff; }
        .band .tag { font-size: 10pt; color: {{ C::brand(200) }}; margin: 2mm 0 0 0; }
        .band .meta { margin-top: 5mm; font-size: 8.5pt; color: {{ C::brand(100) }}; }
        .band .meta strong { color: #ffffff; }
        .accent-rule { height: 1.2mm; background-color: {{ C::accent(300) }}; margin: 0 -18mm 6mm -18mm; }

        /* Sections */
        h2 {
            font-size: 12pt;
            line-height: 1.3;
            font-weight: bold;
            color: {{ C::brand(800) }};
            margin: 9mm 0 3mm 0;
            padding: 0 0 1.5mm 0;
            border-bottom: 1px solid {{ C::brand(200) }};
        }
        h2.first { margin-top: 2mm; }
        h3 { font-size: 10pt; font-weight: bold; color: {{ C::gray(900) }}; margin: 0 0 2mm 0; }

        .keep { page-break-inside: avoid; }
        .hero { text-align: center; margin: 0 0 2mm 0; }
        .hero img.main { max-width: 100%; max-height: 82mm; }
        .thumbs { width: 100%; border-collapse: separate; border-spacing: 3mm 0; margin: 3mm -3mm 0 -3mm; }
        .thumbs td { text-align: center; vertical-align: middle; }
        .thumbs img { max-width: 100%; max-height: 34mm; }

        /* Key specifications: a grid of small grey labels over medium values, like the design page */
        table.specs { width: 100%; border-collapse: collapse; }
        table.specs td { width: 33.33%; padding: 0 3mm 4mm 0; vertical-align: top; }
        .label { font-size: 7pt; text-transform: uppercase; letter-spacing: 0.6pt; color: {{ C::gray(500) }}; }
        .value { font-size: 11pt; font-weight: bold; color: {{ C::gray(900) }}; margin-top: 0.5mm; }

        /* Data tables */
        table.data { width: 100%; border-collapse: collapse; margin: 0 0 2mm 0; }
        table.data thead { display: table-header-group; }
        table.data th {
            background-color: {{ C::brand(50) }};
            color: {{ C::brand(900) }};
            font-size: 7pt;
            text-transform: uppercase;
            letter-spacing: 0.6pt;
            text-align: left;
            padding: 2mm 2.5mm;
            border-bottom: 1px solid {{ C::brand(200) }};
        }
        table.data td { padding: 2mm 2.5mm; border-bottom: 1px solid {{ C::gray(200) }}; vertical-align: top; }
        table.data tr { page-break-inside: avoid; }
        table.data td.num, table.data th.num { text-align: right; }
        table.data td.key { width: 32%; font-weight: bold; color: {{ C::gray(700) }}; }

        /* Component detail */
        .component { margin: 0 0 5mm 0; page-break-inside: avoid; }
        .component .pos {
            display: inline-block;
            background-color: {{ C::brand(100) }};
            color: {{ C::brand(900) }};
            font-weight: bold;
            font-size: 8pt;
            padding: 0.5mm 2.5mm;
        }
        table.ts { width: 100%; border-collapse: collapse; margin: 2mm 0 3mm 0; }
        table.ts td { width: 16.66%; padding: 0 2mm 2mm 0; vertical-align: top; }
        table.ts .label { font-size: 6.5pt; }
        table.ts .value { font-size: 8.5pt; }

        /* Rich text from the editor */
        .rich { font-size: 8.5pt; }
        .rich h1, .rich h2, .rich h3, .rich h4 {
            border: 0; padding: 0; margin: 5mm 0 2mm 0; color: {{ C::gray(900) }}; font-weight: bold;
        }
        .rich h1 { font-size: 12pt; }
        .rich h2 { font-size: 10.5pt; }
        .rich h3 { font-size: 9.5pt; }
        .rich h4 { font-size: 9pt; }
        .rich ul, .rich ol { margin: 0 0 6pt 0; padding-left: 5mm; }
        .rich li { margin-bottom: 1.5pt; }
        .rich li p { margin: 0; }
        .rich blockquote { margin: 0 0 6pt 0; padding: 1mm 0 1mm 4mm; border-left: 2px solid {{ C::brand(300) }}; color: {{ C::gray(600) }}; }
        .rich code, .rich pre { font-family: 'DejaVu Sans Mono', monospace; font-size: 7.5pt; background-color: {{ C::gray(100) }}; }
        .rich img { max-width: 100%; height: auto; }
        .rich hr { border: 0; border-top: 1px solid {{ C::gray(200) }}; margin: 4mm 0; }
        .rich table { width: 100%; border-collapse: collapse; margin: 0 0 4mm 0; }
        .rich table th {
            background-color: {{ C::brand(50) }}; color: {{ C::brand(900) }}; font-weight: bold; text-align: left;
            padding: 1.5mm 2mm; border: 1px solid {{ C::gray(200) }};
        }
        .rich table td { padding: 1.5mm 2mm; border: 1px solid {{ C::gray(200) }}; vertical-align: top; }
        .rich table tr { page-break-inside: avoid; }
        .rich table th p, .rich table td p { margin: 0; padding: 0; }
        .rich .filament-tiptap-grid-builder__column { margin: 0 0 3mm 0; padding: 2mm 3mm; border: 1px solid {{ C::gray(200) }}; }

        /* Fixed footer: repeated on every page, inside the bottom page margin */
        .footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: -19mm;
            height: 14mm;
            border-top: 1px solid {{ C::brand(200) }};
            padding-top: 2mm;
            font-size: 7pt;
            line-height: 1.45;
            color: {{ C::gray(600) }};
        }
        .footer .note { font-size: 6.5pt; color: {{ C::gray(400) }}; margin-top: 0.5mm; }
    </style>
</head>
<body>

<div class="footer">
    @if ($license)
        <div class="licence">{{ $license->line() }}</div>
        <div class="note">For personal use — please don't redistribute.</div>
    @else
        <div class="licence">{{ config('app.name') }} · {{ rtrim((string) config('app.url'), '/') }}</div>
    @endif
</div>

<div class="band">
    <table>
        <tr>
            <td>
                {{-- the site logo, white on the band (SVG: vector, embedded from disk) --}}
                <img class="logo" src="{{ resource_path('pdf/sdlabs-logo-white.svg') }}" alt="SDLabs">
            </td>
            <td class="kind">Design plans</td>
        </tr>
    </table>
    <h1>{{ $pdf['name'] }}</h1>
    @if (filled($pdf['tag']))
        <div class="tag">{{ $pdf['tag'] }}</div>
    @endif
    <div class="meta">
        @if (filled($pdf['designer']))Designed by <strong>{{ $pdf['designer'] }}</strong> &nbsp;&middot;&nbsp; @endif
        Version <strong>{{ $pdf['version'] }}</strong>
        @if (filled($pdf['date'])) &nbsp;&middot;&nbsp; {{ $pdf['date'] }} @endif
    </div>
</div>
<div class="accent-rule"></div>

@if (! empty($pdf['images']))
    <div class="hero">
        {{-- embedded from disk as data URIs: the PDF is rendered without network access --}}
        <img class="main" src="{{ $pdf['images'][0] }}" alt="{{ $pdf['name'] }}">
    </div>
    @if (count($pdf['images']) > 1)
        <table class="thumbs">
            <tr>
                @foreach (array_slice($pdf['images'], 1, 3) as $image)
                    <td><img src="{{ $image }}" alt=""></td>
                @endforeach
            </tr>
        </table>
    @endif
@endif

@if (filled($pdf['summaryHtml']))
    <h2 class="first">Summary</h2>
    <div class="rich">{!! $pdf['summaryHtml'] !!}</div>
@endif

@if (! empty($pdf['specs']))
    <div class="keep">
    <h2>Key specifications</h2>
    <table class="specs">
        @foreach (array_chunk($pdf['specs'], 3, true) as $row)
            <tr>
                @foreach ($row as $label => $value)
                    <td>
                        <div class="label">{{ $label }}</div>
                        <div class="value">{{ $value }}</div>
                    </td>
                @endforeach
                @for ($i = count($row); $i < 3; $i++)
                    <td></td>
                @endfor
            </tr>
        @endforeach
    </table>
    </div>
@endif

@if (! empty($pdf['components']))
    <div class="keep">
    <h2>Components</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Position</th>
                <th>Driver</th>
                <th class="num">Qty</th>
                <th>Frequency range</th>
                <th class="num">Air volume</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($pdf['components'] as $component)
                <tr>
                    <td><strong>{{ $component['position'] }}</strong></td>
                    <td>{{ $component['driver'] }}</td>
                    <td class="num">{{ $component['quantity'] }}</td>
                    <td>{{ $component['range'] }}</td>
                    <td class="num">{{ $component['air_volume'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
@endif

@if (! empty($pdf['bom']))
    <div class="keep">
    <h2>Bill of materials</h2>
    <table class="data">
        <thead>
            <tr><th>Item</th><th>Details</th></tr>
        </thead>
        <tbody>
            @foreach ($pdf['bom'] as $item => $detail)
                <tr>
                    <td class="key">{{ $item }}</td>
                    <td>{{ $detail }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
@endif

@if (filled($pdf['descriptionHtml']))
    <h2>Description</h2>
    <div class="rich">{!! $pdf['descriptionHtml'] !!}</div>
@endif

@foreach ($pdf['components'] as $component)
    <div class="component">
        @if ($loop->first)
            <h2>Component details</h2>
        @endif
        <h3><span class="pos">{{ $component['position'] }}</span>&nbsp; {{ $component['driver'] }}@if ($component['quantity'] > 1) <span class="muted">&times; {{ $component['quantity'] }}</span>@endif</h3>
        @if (! empty($component['specs']))
            <table class="ts">
                @foreach (array_chunk($component['specs'], 6, true) as $row)
                    <tr>
                        @foreach ($row as $label => $value)
                            <td>
                                <div class="label">{{ $label }}</div>
                                <div class="value">{{ $value }}</div>
                            </td>
                        @endforeach
                        @for ($i = count($row); $i < 6; $i++)
                            <td></td>
                        @endfor
                    </tr>
                @endforeach
            </table>
        @endif
        @if (filled($component['html']))
            <div class="rich">{!! $component['html'] !!}</div>
        @endif
    </div>
@endforeach

</body>
</html>
