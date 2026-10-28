<footer class="mt-16 bg-footer text-brand-100">
    <div class="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:grid-cols-2 sm:px-6 lg:grid-cols-4 lg:px-8">
        <div>
            <div class="text-lg font-bold text-white">{{ config('app.name') }}</div>
            <div class="mt-2 text-sm text-brand-200">{{ setting('site.description', 'The Speaker Design Marketplace') }}</div>
        </div>
        <div>
            <h2 class="text-xs font-bold uppercase tracking-wide text-white">Explore</h2>
            <ul class="mt-3 space-y-2 text-sm">
                <li><a class="hover:text-accent-200" href="{{ route('designs') }}">Design Database</a></li>
                <li><a class="hover:text-accent-200" href="{{ route('drivers') }}">Driver Database</a></li>
                <li><a class="hover:text-accent-200" href="{{ route('marketplace') }}">Marketplace</a></li>
                <li><a class="hover:text-accent-200" href="{{ route('blog') }}">Soap Box</a></li>
                <li><a class="hover:text-accent-200" href="/forum">Forum</a></li>
            </ul>
        </div>
        <div>
            <h2 class="text-xs font-bold uppercase tracking-wide text-white">Sell</h2>
            <ul class="mt-3 space-y-2 text-sm">
                <li><a class="hover:text-accent-200" href="{{ route('selling.dashboard') }}">Start selling</a></li>
                <li><a class="hover:text-accent-200" href="{{ route('dashboard.listings') }}">My listings</a></li>
                <li><a class="hover:text-accent-200" href="{{ route('dashboard.designs') }}">My designs</a></li>
            </ul>
        </div>
        <div>
            <h2 class="text-xs font-bold uppercase tracking-wide text-white">Account</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @auth
                    <li><a class="hover:text-accent-200" href="{{ route('dashboard') }}">Dashboard</a></li>
                    <li><a class="hover:text-accent-200" href="{{ route('dashboard.orders') }}">My orders</a></li>
                    <li><a class="hover:text-accent-200" href="{{ route('dashboard.library') }}">My library</a></li>
                    <li><a class="hover:text-accent-200" href="{{ route('cart') }}">Cart</a></li>
                @else
                    <li><a class="hover:text-accent-200" href="{{ route('login') }}">Sign in</a></li>
                    <li><a class="hover:text-accent-200" href="{{ route('register') }}">Register</a></li>
                @endauth
            </ul>
        </div>
    </div>
    <div class="border-t border-brand-900">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-6 gap-y-2 px-4 py-4 text-sm text-brand-300 sm:px-6 lg:px-8">
            <span>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</span>
            @if (\App\Support\Analytics\Ga4::enabled())
                <button type="button" class="underline hover:text-accent-200" data-cookie-settings>Cookie settings</button>
            @endif
        </div>
    </div>
</footer>
