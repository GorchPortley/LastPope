@props([
    'position' => ''
])

<div>
<div x-data="{ dropdownOpen: false }"
     :class="{ 'block z-50 bg-white dark:bg-gray-900 dark:border-gray-800' : open, 'hidden': ! open }"
     class="relative flex-shrink-0 sm:p-0 sm:flex w-auto sm:bg-white sm:items-center" x-cloak>
    <button @click="dropdownOpen=!dropdownOpen"
            class="flex px-3 py-2.5 w-full text-[13px] text-ink hover:bg-paper justify-start justify-between items-center w-full border-t border-rule bg-white space-x-1.5 overflow-hidden group-hover:autoflow-auto items">
        <span class="relative flex items-center space-x-2">
            {{-- Avatars get a new file name on every upload, so the browser may cache them; the profile page sends the new URL. --}}
            <img src="{{ auth()->user()->avatar() }}" @refresh-avatar.window="$event.detail?.src && ($el.src = $event.detail.src)"
                width="20" height="20" class="w-5 h-5 border border-rule object-cover" alt="{{ auth()->user()->name }}"/>
            <span @class(
                    'flex-shrink-0 ease-out duration-50'
                )>{{ Auth::user()->name }}</span>
        </span>
        <svg :class="{ 'rotate-180' : '{{ $position }}' == 'bottom' }"
             class="relative right-0 w-4 h-4 ease-out mr-4 translate-x-0.5 fill-current group-hover:delay-150 duration-0 group-hover:duration-300"
             xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
            <path fill-rule="evenodd"
                  d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                  clip-rule="evenodd"/>
        </svg>
    </button>
    <div wire:ignore x-show="dropdownOpen" @mouse.leave="dropdownOpen=false" @click.away="dropdownOpen=false"
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="transform opacity-0 sm:scale-95"
         x-transition:enter-end="transform opacity-100 sm:scale-100" x-transition:leave="transition ease-in duration-75"
         x-transition:leave-start="transform opacity-100 sm:scale-100"
         x-transition:leave-end="transform opacity-0 sm:scale-95" @class([ 'z-50' , 'left-0  absolute w-full bottom-0 sm:origin-bottom mb-12'=> ($position == 'bottom'),
        'top-0 right-0 sm:origin-top mr-5 mt-14 w-full max-w-xs fixed' => ($position != 'bottom')
        ]) x-cloak>
        <div class="pt-0 mt-1 text-ink-2 bg-white border-rule sm:space-y-0.5 border">
            <div
                class="py-4 px-5 text-[13px] font-semibold text-ink text-ellipsis overflow-hidden whitespace-nowrap">{{ auth()->user()->email }}</div>
            <div class="my-2 w-full h-px bg-hair"></div>
            <div class="flex relative flex-col p-2 space-y-1">
                <x-app.sidebar-link href="/dashboard">View Dashboard</x-app.sidebar-link>
{{--                <x-app.sidebar-link href="/notifications">Notifications</x-app.sidebar-link>--}}
                <x-app.sidebar-link href="{{ '/profile/' . auth()->user()->username }}">Public Profile
                </x-app.sidebar-link>
                <x-app.sidebar-link href="{{ route('settings.profile') }}">Settings</x-app.sidebar-link>
                @if(auth()->user()->isAdmin())
                    <x-app.sidebar-link :ajax="false" href="/admin">View Admin</x-app.sidebar-link>
                @endif
                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <button onclick="event.preventDefault(); this.closest('form').submit();"
                            class="relative w-full flex cursor-pointer text-ink-2 hover:text-ink select-none hover:bg-paper font-medium items-center px-3 py-2 text-[14px] outline-none transition-colors data-[disabled]:pointer-events-none data-[disabled]:opacity-50">
                        <span>Log out</span>
                    </button>
                </form>
                @impersonating
                <x-app.sidebar-link href="{{ route('impersonate.leave') }}" icon="phosphor-user-circle-duotone"
                                    active="false">Leave impersonation
                </x-app.sidebar-link>
                @endImpersonating
            </div>
        </div>
    </div>
</div>
</div>
