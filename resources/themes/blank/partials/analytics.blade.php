{{--
    Google Analytics 4 for every layout (docs/PLAN.md 12). Put it in <head>, before anything else that loads scripts.
    Renders nothing while GA4_MEASUREMENT_ID is empty (dev), so there is no tag, no cookie and no consent banner.

    1. Consent Mode v2: everything is denied by default, so GA sets no cookies until the visitor accepts. A stored
       "granted" choice (cookie sdl_consent, written by partials/consent-banner) is restored before the tag loads.
    2. The tag, with the HMAC user_id for signed-in members (never the id or the e-mail).
    3. window.sdlGa4.track(name, params): the one place events go through. It forwards
         - server-rendered events (<x-analytics.events>, and what Ga4::flash() queued for this page),
         - Livewire / Alpine events: $this->dispatch('ga4', name: ..., params: ...) -> window 'ga4' event,
         - clicks and form submits on elements with data-ga4-click / data-ga4-submit (a JSON {name, params}),
       and sends `search` once the visitor stops typing.
--}}
@php($ga4Id = config('ga4.measurement_id'))
@if (filled($ga4Id))
<script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('consent', 'default', {
        ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied',
        wait_for_update: 500
    });
    (function () {
        var match = document.cookie.match(/(?:^|;\s*){{ preg_quote(config('ga4.consent_cookie')) }}=(granted|denied)/);
        if (match && match[1] === 'granted') {
            gtag('consent', 'update', {ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted', analytics_storage: 'granted'});
        }
    })();
    gtag('js', new Date());
    gtag('config', {{ \Illuminate\Support\Js::from($ga4Id) }}@if ($ga4User = \App\Support\Analytics\Ga4::userId(auth()->user())), {user_id: {{ \Illuminate\Support\Js::from($ga4User) }}}@endif);
</script>
<script async src="https://www.googletagmanager.com/gtag/js?id={{ urlencode($ga4Id) }}"></script>
<script>
    (function (w, d) {
        var cookieName = {{ \Illuminate\Support\Js::from(config('ga4.consent_cookie')) }};
        var searchTimer = null;

        function track(name, params, beacon) {
            if (!name) { return; }
            if (!params || Array.isArray(params)) { params = {}; }  // PHP encodes an empty array as []
            if (beacon) { params.transport_type = 'beacon'; }  // survives the page being left (downloads, submits)

            if (name === 'search') {  // one event per search, not one per keystroke
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () { gtag('event', 'search', params); }, 1200);
                return;
            }

            gtag('event', name, params);
        }

        function setConsent(granted) {
            var value = granted ? 'granted' : 'denied';
            var secure = location.protocol === 'https:' ? '; Secure' : '';
            d.cookie = cookieName + '=' + value + '; Max-Age=31536000; Path=/; SameSite=Lax' + secure;
            gtag('consent', 'update', {ad_storage: value, ad_user_data: value, ad_personalization: value, analytics_storage: value});
            w.dispatchEvent(new CustomEvent('sdl-consent', {detail: {granted: granted}}));
        }

        function fromAttribute(el, attribute) {
            try { return JSON.parse(el.getAttribute(attribute)); } catch (e) { return null; }
        }

        w.sdlGa4 = {track: track, setConsent: setConsent};

        // Livewire dispatch() and Alpine $dispatch(): detail is {name, params}.
        w.addEventListener('ga4', function (event) {
            var detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
            if (detail) { track(detail.name, detail.params); }
        });

        d.addEventListener('click', function (event) {
            var el = event.target.closest && event.target.closest('[data-ga4-click]');
            var payload = el && fromAttribute(el, 'data-ga4-click');
            if (payload) { track(payload.name, payload.params, true); }
        });

        d.addEventListener('submit', function (event) {
            var el = event.target.closest && event.target.closest('[data-ga4-submit]');
            var payload = el && fromAttribute(el, 'data-ga4-submit');
            if (payload) { track(payload.name, payload.params, true); }
        });
    })(window, document);
</script>
<x-analytics.events :events="\App\Support\Analytics\Ga4::pullFlashed()" />
@endif
