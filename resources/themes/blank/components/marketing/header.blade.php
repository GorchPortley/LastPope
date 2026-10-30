@php
    $navLink = 'flex h-full items-center border-b-2 px-3 py-5 font-condensed text-[15px] font-semibold uppercase tracking-[0.05em] transition duration-150 xl:px-4 xl:text-base';
    $navOff = 'border-transparent text-white hover:text-accent-200';
    $navOn = 'border-accent-400 text-white';
    $menuLink = 'block px-4 py-2 font-condensed text-[15px] font-semibold uppercase tracking-[0.04em] text-ink no-underline hover:bg-paper';
    $designActive = request()->is('designs*');
    $driverActive = request()->is('drivers*');
    $marketActive = request()->is('marketplace*');
    $blogActive = request()->is('blog*');
    $forumActive = request()->is('forum*');
@endphp
<section x-data="{ mobileNavOpen: false }" class="bg-header">
    <div class="container mx-auto">
        <div class="flex items-center justify-between bg-transparent">
            <!-- Left side with logo and navigation -->
            <div class="flex items-center">
                <div class="flex-shrink-0 py-5 pl-5 lg:pl-0">
                    <a href="/" class="font-condensed text-[28px] font-bold uppercase leading-none tracking-[0.02em] text-white no-underline" aria-label="{{ config('app.name') }}">SD<span class="text-accent-400">Labs</span></a>
                </div>

                <!-- Desktop Navigation -->
                <div class="hidden lg:block ml-6">
                    <ul class="flex items-center space-x-1">
                        <li x-data="{ isOpen: false }"
                            @mouseenter="isOpen = true"
                            @mouseleave="isOpen = false"
                            class="relative group">
                            <a href="{{ route('designs') }}" class="{{ $navLink }} {{ $designActive ? $navOn : $navOff }}">Design Database</a>
                            <div x-show="isOpen"
                                 x-cloak
                                 x-transition
                                 class="absolute top-full left-0 z-50 mt-0 w-48 border border-ink border-t-2 border-t-accent-400 bg-white">
                                <a href="{{ route('designs') }}" class="{{ $menuLink }}">All Designs</a>
                                <a href="{{ route('designs', ["category" => "Subwoofer"]) }}" class="{{ $menuLink }}">Subwoofer</a>
                                <a href="{{ route('designs', ["category" => "Full-Range"]) }}" class="{{ $menuLink }}">Full-Range</a>
                                <a href="{{ route('designs', ["category" => "Two-Way"]) }}" class="{{ $menuLink }}">Two-Way</a>
                                <a href="{{ route('designs', ["category" => "Three-Way"]) }}" class="{{ $menuLink }}">Three-Way</a>
                                <a href="{{ route('designs', ["category" => "Four-Way+"]) }}" class="{{ $menuLink }}">Four-Way+</a>
                                <a href="{{ route('designs', ["category" => "Portable"]) }}" class="{{ $menuLink }}">Portable</a>
                                <a href="{{ route('designs', ["category" => "Esoteric"]) }}" class="{{ $menuLink }}">Esoteric</a>
                            </div>
                        </li>

                        <li x-data="{ isOpen: false }"
                            @mouseenter="isOpen = true"
                            @mouseleave="isOpen = false"
                            class="relative group">
                            <a href="{{ route('drivers') }}" class="{{ $navLink }} {{ $driverActive ? $navOn : $navOff }}">Driver Database</a>
                            <div x-show="isOpen"
                                 x-cloak
                                 x-transition
                                 class="absolute top-full left-0 z-50 mt-0 w-48 border border-ink border-t-2 border-t-accent-400 bg-white">
                                <a href="{{ route('drivers') }}" class="{{ $menuLink }}">All Drivers</a>
                                <a href="{{ route('drivers', ["category" => "Subwoofer"]) }}" class="{{ $menuLink }}">Subwoofers</a>
                                <a href="{{ route('drivers', ["category" => "Woofer"]) }}" class="{{ $menuLink }}">Woofers</a>
                                <a href="{{ route('drivers', ["category" => "Tweeter"]) }}" class="{{ $menuLink }}">Tweeters</a>
                                <a href="{{ route('drivers', ["category" => "Compression Driver"]) }}" class="{{ $menuLink }}">Compression Drivers</a>
                                <a href="{{ route('drivers', ["category" => "Exciter"]) }}" class="{{ $menuLink }}">Exciters</a>
                                <a href="{{ route('drivers', ["category" => "Other"]) }}" class="{{ $menuLink }}">Others</a>
                            </div>
                        </li>

                        <li class="group">
                            <a href="{{ route('marketplace') }}" class="{{ $navLink }} {{ $marketActive ? $navOn : $navOff }}">Marketplace</a>
                        </li>

                        <li class="group">
                            <a href="{{ route('blog') }}" class="{{ $navLink }} {{ $blogActive ? $navOn : $navOff }}">Soap Box</a>
                        </li>

                        <li class="group">
                            <a href="/forum" class="{{ $navLink }} {{ $forumActive ? $navOn : $navOff }}">Forum</a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Right side with search and user menu -->
            <div class="flex items-center flex-1 justify-end">
                <!-- Search Bar - Takes all available space -->
                <div class="hidden lg:block flex-grow px-3 max-w-[8rem] xl:max-w-[11rem] 2xl:max-w-xl [&_input]:rounded-none [&_input]:border-white/30 [&_input]:text-sm">
                    @livewire('top-search-bar')
                </div>

                <!-- User menu or login -->
                <div class="flex items-center">
                    @auth
                        {{-- the cart badge lives in the cart-icon component; square, amber and mono here --}}
                        <div class="[&_[data-cart-count]]:rounded-none [&_[data-cart-count]]:bg-accent-500 [&_[data-cart-count]]:font-mono [&_[data-cart-count]]:text-[11px] [&_[data-cart-count]]:font-medium [&_[data-cart-count]]:leading-[18px] [&_[data-cart-count]]:text-ink">
                            <livewire:cart-icon />
                        </div>
                    @endauth
                    @if(auth()->guest())
                        <div class="hidden lg:flex lg:items-center">
                            <a href="{{ route('login') }}" class="px-5 py-3 font-condensed text-[15px] font-semibold uppercase tracking-[0.05em] text-white hover:text-accent-200 transition duration-150" type="button">Sign In</a>
                            <a href="{{ route('register') }}" class="ml-2 border-2 border-brand-700 bg-brand-800 px-5 py-2.5 font-condensed text-[15px] font-semibold uppercase tracking-[0.05em] text-white transition duration-150 hover:bg-brand-700" type="button">Register</a>
                        </div>
                    @else
                        {{-- a direct way to the dashboard (it is also in the user menu) --}}
                        <a href="{{ route('dashboard') }}" class="hidden items-center gap-2 border-2 border-white/70 px-4 py-2 font-condensed text-[15px] font-semibold uppercase tracking-[0.05em] text-white transition duration-150 hover:border-accent-300 hover:text-accent-200 lg:inline-flex mr-4" data-header-dashboard>
                            <x-phosphor-squares-four class="h-4 w-4" aria-hidden="true" />Dashboard
                        </a>
                        <div class="hidden lg:block mr-6">
                            <x-app.user-menu position="top"/>
                        </div>
                    @endif

                    <!-- Mobile menu toggle -->
                    <div class="lg:hidden px-5">
                        <button x-on:click="mobileNavOpen = !mobileNavOpen" class="p-2 text-white hover:bg-brand-900" aria-label="Menu">
                            <x-phosphor-list class="w-6 h-6" />
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Navigation Drawer -->
    <div :class="{'block': mobileNavOpen, 'hidden': !mobileNavOpen}" class="fixed inset-0 z-50 lg:hidden">
        <div x-on:click="mobileNavOpen = !mobileNavOpen" class="fixed inset-0 bg-ink opacity-80"></div>
        <nav class="relative z-10 h-full w-4/6 max-w-sm transform overflow-y-auto bg-white transition-transform duration-200 ease-in-out">
            <div class="flex flex-col h-full">
                <!-- Top with logo and close button -->
                <div class="flex items-center justify-between border-b border-rule p-4">
                    <a href="/" class="font-condensed text-[28px] font-bold uppercase leading-none tracking-[0.02em] text-ink no-underline" aria-label="{{ config('app.name') }}">SD<span class="text-brand-800">Labs</span></a>
                    <button x-on:click="mobileNavOpen = !mobileNavOpen" class="p-2 hover:bg-paper" aria-label="Close menu">
                        <svg width="24" height="24" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M6 18L18 6M6 6L18 18" stroke="#111827" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
                        </svg>
                    </button>
                </div>
                
                <!-- Mobile search -->
                <div class="border-b border-rule px-4 py-3">
                    @livewire('top-search-bar')
                </div>
                
                <!-- Navigation links -->
                <div class="flex-grow py-6">
                    <ul class="px-4 space-y-6">
                        <li class="py-2">
                            <a class="font-condensed text-xl font-bold uppercase tracking-[0.03em] text-ink hover:text-brand-800" href="{{route('designs')}}">Design Database</a>
                            <ul class="mt-2 space-y-2 border-l border-rule pl-4 text-ink-2">
                                <li><a href="{{ route('designs', ["category" => "Subwoofer"]) }}" class="hover:text-brand-700">Subwoofers</a></li>
                                <li><a href="{{ route('designs', ["category" => "Full-Range"]) }}" class="hover:text-brand-700">Full-Range</a></li>
                                <li><a href="{{ route('designs', ["category" => "Two-Way"]) }}" class="hover:text-brand-700">Two-Way</a></li>
                                <li><a href="{{ route('designs', ["category" => "Three-Way"]) }}" class="hover:text-brand-700">Three-Way</a></li>
                                <li><a href="{{ route('designs', ["category" => "Four-Way+"]) }}" class="hover:text-brand-700">Four-Way +</a></li>
                                <li><a href="{{ route('designs', ["category" => "Portable"]) }}" class="hover:text-brand-700">Portable</a></li>
                                <li><a href="{{ route('designs', ["category" => "Esoteric"]) }}" class="hover:text-brand-700">Esoteric</a></li>
                            </ul>
                        </li>
                        <li class="py-2">
                            <a class="font-condensed text-xl font-bold uppercase tracking-[0.03em] text-ink hover:text-brand-800" href="{{route('drivers')}}">Driver Database</a>
                            <ul class="mt-2 space-y-2 border-l border-rule pl-4 text-ink-2">
                                <li><a href="{{ route('drivers') }}" class="hover:text-brand-700">All Drivers</a></li>
                                <li><a href="{{ route('drivers', ["category" => "Subwoofer"]) }}" class="hover:text-brand-700">Subwoofers</a></li>
                                <li><a href="{{ route('drivers', ["category" => "Woofer"]) }}" class="hover:text-brand-700">Woofers</a></li>
                                <li><a href="{{ route('drivers', ["category" => "Tweeter"]) }}" class="hover:text-brand-700">Tweeters</a></li>
                                <li><a href="{{ route('drivers', ["category" => "Compression Driver"]) }}" class="hover:text-brand-700">Compression Drivers</a></li>
                                <li><a href="{{ route('drivers', ["category" => "Exciter"]) }}" class="hover:text-brand-700">Exciters</a></li>
                                <li><a href="{{ route('drivers', ["category" => "Other"]) }}" class="hover:text-brand-700">Others</a></li>
                            </ul>
                        </li>
                        <li class="py-2"><a class="font-condensed text-xl font-bold uppercase tracking-[0.03em] text-ink hover:text-brand-800" href="{{ route('marketplace') }}">Marketplace</a></li>
                        <li class="py-2"><a class="font-condensed text-xl font-bold uppercase tracking-[0.03em] text-ink hover:text-brand-800" href="/blog">Soap Box</a></li>
                        <li class="py-2"><a class="font-condensed text-xl font-bold uppercase tracking-[0.03em] text-ink hover:text-brand-800" href="/forum">Community Forum</a></li>
                    </ul>
                </div>
                
                <!-- Bottom user actions -->
                <div class="border-t border-rule p-4">
                    @if(auth()->guest())
                        <div class="space-y-3">
                            <a href="{{ route('login') }}" class="sd-btn w-full" type="button">Sign In</a>
                            <a href="{{ route('register') }}" class="sd-btn sd-btn-go w-full">Register</a>
                        </div>
                    @else
                        <a href="{{ route('dashboard') }}" class="sd-btn sd-btn-go mb-3 w-full" data-header-dashboard>Dashboard</a>
                        <x-app.user-menu position="bottom" class="border-black" />
                    @endif
                </div>
            </div>
        </nav>
    </div>
</section>