{{-- businesslike: same theme, but the footer carries no marketing links --}}
<x-mail::message variant="business">
# Purchase order {{ $po->number }}

Hello {{ $supplier->contact_name ?: $supplier->name }},

Please supply and ship the items below. Reference this purchase order number ({{ $po->number }}) on your confirmation and your tracking details.
@if (filled($supplier->account_number))

Our account number with you: **{{ $supplier->account_number }}**
@endif

<x-mail::table>
| SKU | Item | Qty | Unit cost |
|:----|:-----|:---:|----------:|
@foreach ($lines as $line)
| {{ str_replace(['|', "\n"], ['\\|', ' '], (string) $line->supplier_sku) ?: '-' }} | {{ str_replace(['|', "\n"], ['\\|', ' '], $line->title) }} | {{ $line->quantity }} | {{ money($line->unit_cost_cents) }} |
@endforeach
</x-mail::table>

Items total at your prices: **{{ $itemsTotal }}**

## Ship to

@foreach ($shipTo as $line)
{{ $line }}<br>
@endforeach
@if ($supplier->blind_dropship)

## Blind dropship

This parcel goes straight to our customer. Please ship it **without** your invoice, a packing slip with prices, catalogues or any branding of yours, and do not contact the recipient. If a packing slip is needed, use a neutral one without prices, with our purchase order number {{ $po->number }} as the only reference.
@endif

Please reply to this e-mail ({{ $replyTo }}) with your order confirmation, and again with carrier and tracking number once the parcel has shipped. The same lines are attached as a CSV file.

Thanks,<br>
{{ $storeName }}
</x-mail::message>
