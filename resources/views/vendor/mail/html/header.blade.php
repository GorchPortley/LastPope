{{--
    The SDLabs header: a brand-colour band with the logo (the ring mark of the site logo, a PNG because mail clients often
    block SVG, public/images/email/logo.png) next to "SDLabs.cc" as live text, so it reads the same with images blocked,
    and an amber rule under it. $variant "business" (supplier mail) shows $label at the right.
--}}
<tr>
    <td class="header-band" align="center" bgcolor="#3b0764">
        <table class="header-inner" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
            <tr>
                <td class="wordmark-cell">
                    <table cellpadding="0" cellspacing="0" role="presentation">
                        <tr>
                            <td class="logo-cell" width="36" valign="middle" style="width: 36px;">
                                <a href="{{ $url }}" style="text-decoration: none;"><img class="logo" src="{{ asset('images/email/logo.png') }}" width="36" height="36" alt="SDLabs.cc" style="display: block; width: 36px; height: 36px; border: 0; overflow: hidden; color: #ffffff; font-size: 1px;"></a>
                            </td>
                            <td class="logo-text-cell" valign="middle" style="padding-left: 12px;">
                                <a href="{{ $url }}" class="wordmark" style="color: #ffffff; text-decoration: none;">SDLabs.cc</a>
                            </td>
                        </tr>
                    </table>
                </td>
                @if (($variant ?? 'default') === 'business')
                    <td class="tagline-cell" align="right">{{ $label ?? '' }}</td>
                @endif
            </tr>
        </table>
    </td>
</tr>
<tr>
    <td class="header-accent" height="4" bgcolor="#fcd34d" style="font-size: 0; line-height: 0;">&nbsp;</td>
</tr>
