{{ config('app.name') }}
@if (($variant ?? 'default') === 'business')
Sent by {{ config('app.name') }}. Just reply to this e-mail to reach us.
@else
The speaker design marketplace
Designs: {{ route('designs') }}
Drivers: {{ route('drivers') }}
Marketplace: {{ route('marketplace') }}
Dashboard: {{ route('dashboard') }}

You're receiving this because you have an account at {{ config('app.name') }}.
@endif
© {{ date('Y') }} {{ config('app.name') }}
