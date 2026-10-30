{{--
    The measured-response chart of a design as a Livewire component (the design page itself draws the same
    <x-design.response-chart> from its own data). The chart is an inline SVG drawn by the theme's response-chart.js.
--}}
<div>
    <x-design.response-chart :chart="\App\Support\ResponseChartData::from(['chartData' => $chartData, 'summedResponse' => $summedResponse])" />
</div>
