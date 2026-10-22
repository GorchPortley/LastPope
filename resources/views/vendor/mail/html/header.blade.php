{{-- The SDLabs header: a brand-colour band with the wordmark as text, and an amber rule under it. $variant "business" (supplier mail) drops the marketing tagline. --}}
<tr>
    <td class="header-band" align="center" bgcolor="#3b0764">
        <table class="header-inner" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
            <tr>
                <td class="wordmark-cell">
                    <a href="{{ $url }}" class="wordmark" style="color: #ffffff; text-decoration: none;">{{ $slot }}</a>
                </td>
                <td class="tagline-cell" align="right">
                    {{ ($variant ?? 'default') === 'business' ? ($label ?? '') : 'The speaker design marketplace' }}
                </td>
            </tr>
        </table>
    </td>
</tr>
<tr>
    <td class="header-accent" height="4" bgcolor="#fcd34d" style="font-size: 0; line-height: 0;">&nbsp;</td>
</tr>
