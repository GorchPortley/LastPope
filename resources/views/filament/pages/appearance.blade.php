<x-filament-panels::page>
    @php
        $seasons = $this->getSeasons();
        $live = $this->getLiveSeason();
    @endphp

    <form wire:submit="save" class="space-y-6">
        <x-filament::section
            heading="Seasonal theme"
            description="Re-colours the public site (header, footer, buttons, badges, links) and adds a small header decoration. You switch it on and off by hand; nothing changes by date. The admin panel is not affected."
        >
            <fieldset>
                <legend class="sr-only">Season</legend>

                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($seasons as $key => $season)
                        <label
                            wire:key="season-{{ $key }}"
                            @class([
                                'relative flex cursor-pointer flex-col gap-3 rounded-xl p-4 text-sm shadow-sm transition',
                                'bg-white ring-2 ring-primary-600 dark:bg-white/5 dark:ring-primary-500' => $this->season === $key,
                                'bg-white ring-1 ring-gray-950/10 hover:ring-gray-950/30 dark:bg-white/5 dark:ring-white/10 dark:hover:ring-white/30' => $this->season !== $key,
                            ])
                        >
                            <input type="radio" name="season" value="{{ $key }}" wire:model.live="season" class="sr-only" />

                            <span class="flex items-start justify-between gap-2">
                                <span class="font-semibold text-gray-950 dark:text-white">{{ $season['label'] }}</span>
                                @if ($live === $key)
                                    <span class="rounded-full bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 ring-1 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400">Live</span>
                                @endif
                            </span>

                            <span class="flex items-center gap-2" aria-hidden="true">
                                @foreach ($season['swatches'] as $hex)
                                    <span class="h-7 w-7 rounded-full ring-1 ring-gray-950/15" style="background-color: {{ $hex }}"></span>
                                @endforeach
                            </span>

                            <span class="text-gray-600 dark:text-gray-400">{{ $season['description'] }}</span>

                            <span class="mt-auto flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                                <span>
                                    @if ($season['effect'] === 'snow') Falling snow @elseif ($season['garnish']) Header decoration @else No decorations @endif
                                </span>
                                @if ($key !== 'none')
                                    <a href="{{ $season['preview_url'] }}" target="_blank" rel="noopener" class="font-medium text-primary-600 hover:underline dark:text-primary-400" x-on:click.stop>Preview</a>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>

                @error('season')
                    <p class="mt-3 text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </fieldset>
        </x-filament::section>

        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button type="submit">Save</x-filament::button>
            @if ($this->season !== $live)
                <span class="text-sm text-gray-600 dark:text-gray-400">
                    Selected: {{ $seasons[$this->season]['label'] ?? $this->season }}. Live now: {{ $seasons[$live]['label'] ?? $live }}. Not saved yet.
                </span>
            @endif
        </div>
    </form>
</x-filament-panels::page>
