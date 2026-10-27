{{-- Admin > Store > Margins: the margin report (App\Services\Marketplace\MarginReport) for a date range. --}}
@php
    $report = $this->report();
    $totals = $report['totals'];
    $rows = $report['rows'];
    $netRevenue = $totals->netRevenueCents();
    $margin = $totals->marginCents();
    $percent = $totals->marginPercent();
    $minBps = max(0, (int) config('marketplace.min_margin_bps', 1500));
    $marginClass = fn (int $cents, ?int $bps): string => match (true) {
        $cents < 0 => 'text-danger-600 dark:text-danger-400',
        $bps !== null && $bps < $minBps => 'text-warning-600 dark:text-warning-400',
        default => 'text-success-600 dark:text-success-400',
    };
    $cards = [
        ['label' => 'Revenue', 'value' => money($totals->revenueCents()), 'note' => 'items '.money($totals->itemsCents).' + shipping '.money($totals->shippingCents), 'key' => 'revenue'],
        ['label' => 'Costs', 'value' => money($totals->costsCents()), 'note' => 'goods '.money($totals->goodsCostCents).', supplier shipping '.money($totals->supplierShippingCents).', fees '.money($totals->dropshipFeesCents), 'key' => 'costs'],
        ['label' => $totals->stripeFeeIsEstimate() ? 'Stripe fees (est.)' : 'Stripe fees', 'value' => money($totals->stripeFeeCents), 'note' => $totals->stripeFeeIsEstimate()
            ? ($totals->orders - $totals->estimatedFees).' of '.$totals->orders.' '.\Illuminate\Support\Str::plural('order', $totals->orders).' charged by Stripe, '.$totals->estimatedFees.' estimated'
            : 'charged by Stripe, '.$totals->orders.' '.\Illuminate\Support\Str::plural('order', $totals->orders), 'key' => 'stripe-fees'],
        ['label' => 'Refunds', 'value' => money($totals->refundsCents), 'note' => 'given back to buyers', 'key' => 'refunds'],
        ['label' => 'Margin', 'value' => money($margin), 'note' => 'after costs, fees and refunds', 'key' => 'margin', 'class' => $marginClass($margin, $totals->marginBps())],
        ['label' => 'Margin %', 'value' => $percent === null ? '-' : number_format($percent, 1).' %', 'note' => 'of the revenue kept', 'key' => 'margin-percent', 'class' => $marginClass($margin, $totals->marginBps())],
    ];
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <form wire:submit.prevent class="space-y-4">
            {{ $this->form }}
        </form>
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400" data-margin-range>
            Paid orders from {{ $this->rangeLabel() }}: the SDLabs store's part of each order. Stripe's fee is the one Stripe charged for the payment;
            until Stripe reports it, it is an estimate ({{ number_format((int) config('marketplace.stripe_fee_bps', 290) / 100, 2) }} % + {{ money((int) config('marketplace.stripe_fee_fixed_cents', 30)) }} per order), marked (est.).
        </p>
    </x-filament::section>

    <div class="grid grid-cols-2 gap-4 md:grid-cols-3" data-margin-totals>
        @foreach ($cards as $card)
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" data-margin-card="{{ $card['key'] }}">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                <p class="mt-1 text-2xl font-semibold tracking-tight {{ $card['class'] ?? 'text-gray-950 dark:text-white' }}">{{ $card['value'] }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $card['note'] }}</p>
            </div>
        @endforeach
    </div>

    <x-filament::section heading="Orders" :description="$rows->count().' paid '.\Illuminate\Support\Str::plural('order', $rows->count()).' with a store part'">
        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400" data-margin-empty>No paid store orders in this period.</p>
        @else
            <div class="-mx-6 overflow-x-auto sm:mx-0">
                <table class="w-full min-w-[48rem] divide-y divide-gray-200 text-start text-sm dark:divide-white/10" data-margin-table>
                    <thead>
                        <tr class="text-gray-500 dark:text-gray-400">
                            <th class="px-3 py-2 text-start font-medium">Order</th>
                            <th class="px-3 py-2 text-end font-medium">Revenue</th>
                            <th class="px-3 py-2 text-end font-medium">Refunds</th>
                            <th class="px-3 py-2 text-end font-medium">Goods</th>
                            <th class="px-3 py-2 text-end font-medium">Supplier ship.</th>
                            <th class="px-3 py-2 text-end font-medium">Fees</th>
                            <th class="px-3 py-2 text-end font-medium">{{ $totals->stripeFeeIsEstimate() ? 'Stripe (est.)' : 'Stripe' }}</th>
                            <th class="px-3 py-2 text-end font-medium">Margin</th>
                            <th class="px-3 py-2 text-end font-medium">%</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows as $row)
                            <tr data-margin-row="{{ $row->orderNumber }}">
                                <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">
                                    <a href="{{ \App\Filament\Resources\OrderResource::getUrl('view', ['record' => $row->orderNumber]) }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row->orderNumber }}</a>
                                    @if ($row->costsPending)
                                        <span class="ms-1 whitespace-nowrap rounded-md bg-warning-50 px-1.5 py-0.5 text-xs font-medium text-warning-700 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400" title="A supplier's shipping cost is not entered yet, or a cost is unknown">costs pending</span>
                                    @endif
                                    <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">paid {{ $row->paidAt?->format('M j, Y') }}</span>
                                </td>
                                <td class="px-3 py-2 text-end">{{ money($row->revenueCents()) }}</td>
                                <td class="px-3 py-2 text-end">{{ $row->refundsCents > 0 ? money($row->refundsCents) : '-' }}</td>
                                <td class="px-3 py-2 text-end">{{ money($row->goodsCostCents) }}</td>
                                <td class="px-3 py-2 text-end">{{ money($row->supplierShippingCents) }}</td>
                                <td class="px-3 py-2 text-end">{{ money($row->dropshipFeesCents) }}</td>
                                <td class="px-3 py-2 text-end" @if ($row->stripeFeeIsEstimate()) data-fee-estimate title="Estimated: Stripe has not reported the fee of this payment yet" @endif>{{ money($row->stripeFeeCents) }}@if ($row->stripeFeeIsEstimate()) <span class="text-xs text-gray-500 dark:text-gray-400">(est.)</span>@endif</td>
                                <td class="px-3 py-2 text-end font-semibold {{ $marginClass($row->marginCents(), $row->marginBps()) }}">{{ money($row->marginCents()) }}</td>
                                <td class="px-3 py-2 text-end {{ $marginClass($row->marginCents(), $row->marginBps()) }}">{{ $row->marginPercent() === null ? '-' : number_format($row->marginPercent(), 1) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-200 font-semibold text-gray-950 dark:border-white/10 dark:text-white" data-margin-total-row>
                            <td class="px-3 py-2">Total ({{ $totals->orders }} {{ \Illuminate\Support\Str::plural('order', $totals->orders) }})</td>
                            <td class="px-3 py-2 text-end">{{ money($totals->revenueCents()) }}</td>
                            <td class="px-3 py-2 text-end">{{ money($totals->refundsCents) }}</td>
                            <td class="px-3 py-2 text-end">{{ money($totals->goodsCostCents) }}</td>
                            <td class="px-3 py-2 text-end">{{ money($totals->supplierShippingCents) }}</td>
                            <td class="px-3 py-2 text-end">{{ money($totals->dropshipFeesCents) }}</td>
                            <td class="px-3 py-2 text-end">{{ money($totals->stripeFeeCents) }}@if ($totals->stripeFeeIsEstimate()) <span class="text-xs font-normal text-gray-500 dark:text-gray-400">(est.)</span>@endif</td>
                            <td class="px-3 py-2 text-end {{ $marginClass($margin, $totals->marginBps()) }}">{{ money($margin) }}</td>
                            <td class="px-3 py-2 text-end {{ $marginClass($margin, $totals->marginBps()) }}">{{ $percent === null ? '-' : number_format($percent, 1) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
