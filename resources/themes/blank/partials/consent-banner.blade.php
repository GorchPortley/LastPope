{{--
    The analytics consent banner (docs/PLAN.md 12), used by every layout just before </body>. Renders nothing while
    GA4_MEASUREMENT_ID is empty. It shows until the visitor chose; the choice is kept in the first-party cookie
    sdl_consent (granted / denied) by window.sdlGa4.setConsent() from partials/analytics. Anything with
    data-cookie-settings (the footer's "Cookie settings") opens it again.
--}}
@if (filled(config('analytics.measurement_id')))
    <div id="cookie-consent" role="dialog" aria-live="polite" aria-label="Cookie settings" hidden
         class="fixed inset-x-0 bottom-0 z-[60] border-t-2 border-gray-100 bg-white shadow-lg">
        <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-4 sm:px-6 md:flex-row md:items-center md:justify-between lg:px-8">
            <p class="text-sm text-gray-700">
                We use cookies to count visits and see which pages and designs are useful. Nothing is stored unless you accept.
                You can change your mind any time with "Cookie settings" in the footer.
            </p>
            <div class="flex flex-shrink-0 gap-3">
                <x-button type="button" color="gray" size="sm" data-cookie-choice="denied">Reject</x-button>
                <x-button type="button" size="sm" data-cookie-choice="granted">Accept</x-button>
            </div>
        </div>
    </div>
    <script>
        (function (d) {
            var banner = d.getElementById('cookie-consent');
            var chosen = new RegExp('(?:^|;\\s*)' + {{ \Illuminate\Support\Js::from(config('analytics.consent_cookie')) }} + '=(granted|denied)');

            if (!d.cookie.match(chosen)) { banner.hidden = false; }

            d.addEventListener('click', function (event) {
                var choice = event.target.closest && event.target.closest('[data-cookie-choice]');
                if (choice) {
                    window.sdlGa4.setConsent(choice.getAttribute('data-cookie-choice') === 'granted');
                    banner.hidden = true;
                } else if (event.target.closest && event.target.closest('[data-cookie-settings]')) {
                    banner.hidden = false;
                }
            });
        })(document);
    </script>
@endif
