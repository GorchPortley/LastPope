<?php

    use function Laravel\Folio\{middleware, name};
    use Livewire\Volt\Component;
    use Livewire\Attributes\Computed;
    name('wave.profile');

    new class extends Component
    {
        public $username;
        #[Computed]
        public function user()
        {
            return config('wave.user_model')::where('username', '=', $this->username)
                ->with(['roles', 'sellerProfile'])
                ->firstOrFail();
        }

        /** Published designs of this member, with what the cards need loaded in one go. */
        #[Computed]
        public function designs()
        {
            return \App\Models\Design::query()->active()->where('user_id', $this->user->getKey())
                ->with(['designer.roles', 'designer.sellerProfile'])
                ->withAvg('reviews', 'rating')->withCount(['reviews', 'components'])
                ->latest()->get();
        }

        #[Computed]
        public function drivers()
        {
            return \App\Models\Driver::query()->where('active', 1)->where('user_id', $this->user->getKey())
                ->withCount('designs')->with('activeStoreListing.supplier')
                ->latest()->get();
        }

        /** What this member sells on the marketplace (the shop page shows the same). */
        #[Computed]
        public function listings()
        {
            return \App\Models\Listing::query()->marketplace()->forSale()
                ->with(['seller.sellerProfile', 'driver', 'design'])
                ->where('seller_id', $this->user->getKey())
                ->latest()->limit(12)->get();
        }
    }
?>

<x-dynamic-component :component="((auth()->guest()) ? 'layouts.marketing' : 'layouts.app')">
    @volt('wave.profile')

        <x-app.container>

            <div class="@guest min-h-[60vh] bg-paper @endguest">
                <div class="mx-auto max-w-7xl space-y-10 @guest px-4 pb-12 pt-6 sm:px-6 lg:px-8 @endguest">

                    <!-- Profile header sheet -->
                    <header class="sd-sheet flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:p-6">
                        <img src="{{ $this->user->avatar() }}" alt="" class="h-24 w-24 flex-shrink-0 rounded-full border border-rule bg-paper object-cover">
                        <div class="min-w-0 flex-1">
                            <p class="sd-label">Member</p>
                            <h1 class="sd-title mt-1 text-3xl sm:text-4xl">{{ $this->user->name }}</h1>
                            <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 font-mono text-[13px] text-ink-2">
                                <span>{{ '@' . $this->user->username }}</span>
                                <span>Joined {{ $this->user->created_at?->format('F Y') }}</span>
                            </p>
                            @if (filled($this->user->profile('about')))
                                <p class="mt-3 max-w-2xl whitespace-pre-line text-base text-ink-2">{{ $this->user->profile('about') }}</p>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-2 sm:flex-col">
                            @if ($this->user->sellerProfile?->slug)
                                <a href="{{ route('seller', ['slug' => $this->user->sellerProfile->slug]) }}" class="sd-btn sd-btn-sm">Visit shop</a>
                            @endif
                            @if (auth()->check() && auth()->user()->isAdmin())
                                <a href="{{ route('impersonate', $this->user->id) }}" class="sd-btn sd-btn-sm">Impersonate</a>
                            @endif
                        </div>
                    </header>

                    <!-- Designs -->
                    <section aria-labelledby="profile-designs">
                        <div class="sd-section-head mb-4">
                            <h2 id="profile-designs" class="sd-title text-3xl">Submitted designs <span class="font-mono text-sm font-normal normal-case tracking-normal text-ink-2">({{ $this->designs->count() }})</span></h2>
                        </div>
                        @if ($this->designs->isEmpty())
                            <p class="sd-sheet px-5 py-6 text-ink-2">No published designs yet.</p>
                        @else
                            <x-marketing.design-card-container :designs="$this->designs" :padded="false" list-name="Member designs" />
                        @endif
                    </section>

                    <!-- Drivers -->
                    @if ($this->drivers->isNotEmpty())
                        <section aria-labelledby="profile-drivers">
                            <div class="sd-section-head mb-4">
                                <h2 id="profile-drivers" class="sd-title text-3xl">Drivers <span class="font-mono text-sm font-normal normal-case tracking-normal text-ink-2">({{ $this->drivers->count() }})</span></h2>
                            </div>
                            <x-marketing.driver-card-container :drivers="$this->drivers" :padded="false" list-name="Member drivers" />
                        </section>
                    @endif

                    <!-- Marketplace listings -->
                    @if ($this->listings->isNotEmpty())
                        <section aria-labelledby="profile-listings">
                            <div class="sd-section-head mb-4">
                                <h2 id="profile-listings" class="sd-title text-3xl">For sale <span class="font-mono text-sm font-normal normal-case tracking-normal text-ink-2">({{ $this->listings->count() }})</span></h2>
                            </div>
                            <x-marketing.listing-card-container :listings="$this->listings" :padded="false" list-name="Member listings" />
                        </section>
                    @endif
                </div>
            </div>

        </x-app.container>
    @endvolt

</x-dynamic-component>
