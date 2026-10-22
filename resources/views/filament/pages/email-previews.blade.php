{{--
    Admin > Settings > Email previews (App\Filament\Pages\EmailPreviews): each e-mail in an iframe (srcdoc, sandboxed, no
    scripts), at desktop width or at phone width.
--}}
@php
    $groups = $this->getGroups();
    $count = collect($groups)->flatten(1)->count();
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Every e-mail the app sends, rendered with made-up data in the {{ config('app.name') }} mail theme
            (<code>resources/views/vendor/mail/html/themes/sdlabs.css</code>). Nothing is sent and nothing is saved.
            {{ $count }} {{ \Illuminate\Support\Str::plural('e-mail', $count) }}.
            The forum's own e-mails are sent by Flarum with its own templates and are not shown here.
        </p>

        <nav class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-sm" aria-label="E-mail groups">
            @foreach ($groups as $group => $previews)
                <a href="#email-group-{{ \Illuminate\Support\Str::slug($group) }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $group }}</a>
            @endforeach
        </nav>
    </x-filament::section>

    @foreach ($groups as $group => $previews)
        <div class="space-y-4" id="email-group-{{ \Illuminate\Support\Str::slug($group) }}">
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $group }}</h2>

            <div class="grid gap-6 xl:grid-cols-2">
                @foreach ($previews as $key => $preview)
                    <section
                        wire:key="email-preview-{{ $key }}"
                        data-email-preview="{{ $key }}"
                        x-data="{ phone: false }"
                        class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10"
                    >
                        <header class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="font-semibold text-gray-950 dark:text-white">{{ $preview['label'] }}</h3>
                                <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-400">{{ $preview['description'] }}</p>
                                <p class="mt-0.5 font-mono text-xs text-gray-400 dark:text-gray-500">{{ $preview['source'] }}</p>
                            </div>
                            <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 ring-1 ring-gray-950/10 dark:bg-white/10 dark:text-gray-300 dark:ring-white/10">{{ $preview['audience'] }}</span>
                        </header>

                        @if ($preview['error'])
                            <div class="rounded-lg bg-danger-50 p-3 text-sm text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400" data-email-error>
                                This e-mail could not be rendered: {{ $preview['error'] }}
                            </div>
                        @else
                            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-sm">
                                <dt class="text-gray-500 dark:text-gray-400">Subject</dt>
                                <dd class="min-w-0 break-words font-medium text-gray-950 dark:text-white">{{ $preview['subject'] }}</dd>
                                <dt class="text-gray-500 dark:text-gray-400">To</dt>
                                <dd class="min-w-0 break-words text-gray-700 dark:text-gray-300">{{ $preview['to'] }}</dd>
                            </dl>

                            <div class="flex gap-2 text-xs">
                                <button type="button" x-on:click="phone = false" x-bind:class="phone ? 'text-gray-600 dark:text-gray-400' : 'bg-gray-100 font-semibold text-gray-950 dark:bg-white/10 dark:text-white'" class="rounded-md px-2 py-1">Desktop</button>
                                <button type="button" x-on:click="phone = true" x-bind:class="phone ? 'bg-gray-100 font-semibold text-gray-950 dark:bg-white/10 dark:text-white' : 'text-gray-600 dark:text-gray-400'" class="rounded-md px-2 py-1">Phone</button>
                            </div>

                            <div class="overflow-x-auto rounded-lg bg-gray-100 p-2 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                <iframe
                                    title="{{ $preview['label'] }}"
                                    srcdoc="{{ $preview['html'] }}"
                                    sandbox="allow-popups allow-popups-to-escape-sandbox"
                                    loading="lazy"
                                    class="mx-auto block h-[720px] max-w-full border-0 bg-white"
                                    x-bind:style="phone ? 'width: 390px' : 'width: 100%'"
                                ></iframe>
                            </div>

                            @if (filled($preview['text']))
                                <details class="text-sm">
                                    <summary class="cursor-pointer font-medium text-primary-600 dark:text-primary-400">Plain-text version</summary>
                                    <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-white/5 dark:text-gray-300">{{ $preview['text'] }}</pre>
                                </details>
                            @endif
                        @endif
                    </section>
                @endforeach
            </div>
        </div>
    @endforeach
</x-filament-panels::page>
