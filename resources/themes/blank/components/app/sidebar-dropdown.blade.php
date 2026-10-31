<div class="relative w-full select-none pt-3">
    <div
        x-data="{ {{ $id }}: '{{ ($open ?? false) }}' }"
        @click="{{ $id }}=!{{ $id }}"
        class="flex h-auto w-full cursor-pointer items-center justify-start overflow-hidden px-3 py-1.5 text-ink-3 hover:text-ink"
    >
        <div class="relative flex h-auto w-full items-center">
            <span class="font-condensed text-[12px] font-semibold uppercase tracking-[0.1em]">{{ $text }}</span>
            <span :class="{ 'rotate-180' : {{ $id }} == true }" class="ml-auto h-3.5 w-3.5 duration-300 ease-out">
                <x-phosphor-caret-down class="h-full w-full" />
            </span>
        </div>

        <template x-teleport="#{{ $id }}">
            <div class="relative space-y-0.5 pt-1" x-show="{{ $id }}" x-collapse x-cloak>
                {{ $slot }}
            </div>
        </template>
    </div>

    <div id="{{ $id }}" class="relative"></div>

</div>
