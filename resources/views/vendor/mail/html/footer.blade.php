{{--
    The SDLabs footer. Default: site name, tagline, the main links and why the e-mail was sent. $variant "business"
    (the purchase order to a supplier): no marketing links, just who sent it.
--}}
@php($appName = config('app.name'))
<tr>
    <td>
        <table class="footer site-footer" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
            <tr>
                <td class="footer-cell" align="center">
                    <p class="footer-brand">{{ $appName }}</p>
                    @if (($variant ?? 'default') === 'business')
                        <p>Sent by {{ $appName }}. Just reply to this e-mail to reach us.</p>
                        <p class="footer-legal">&copy; {{ date('Y') }} {{ $appName }}</p>
                    @else
                        <p class="footer-tagline">The speaker design marketplace</p>
                        <p class="footer-links">
                            <a href="{{ route('designs') }}">Designs</a> &nbsp;&middot;&nbsp;
                            <a href="{{ route('drivers') }}">Drivers</a> &nbsp;&middot;&nbsp;
                            <a href="{{ route('marketplace') }}">Marketplace</a> &nbsp;&middot;&nbsp;
                            <a href="{{ route('dashboard') }}">Dashboard</a>
                        </p>
                        <p class="footer-legal">You're receiving this because you have an account at {{ $appName }}.</p>
                        <p class="footer-legal">&copy; {{ date('Y') }} {{ $appName }}. All rights reserved.</p>
                    @endif
                </td>
            </tr>
        </table>
    </td>
</tr>
