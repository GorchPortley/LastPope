{{-- Body of the "All versions" modal of the library. $design: Design with snapshots loaded. --}}
@php($snapshots = $design->snapshots->sortByDesc('id')->values())

@if ($snapshots->isEmpty())
    <p class="text-sm text-gray-500">No files have been published yet. They appear here as soon as the author creates a snapshot.</p>
@else
    <ul class="divide-y divide-gray-200">
        @foreach ($snapshots as $snapshot)
            <li class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-gray-950">
                        {{ $snapshot->snapshot_name ?: 'Snapshot '.$snapshot->version }}
                        @if ($loop->first)<x-marketplace.badge color="success" class="ml-1">Latest</x-marketplace.badge>@endif
                    </p>
                    <p class="text-xs text-gray-500">
                        Version {{ $snapshot->version }}
                        @if ($snapshot->file_size) &middot; {{ \Illuminate\Support\Number::fileSize($snapshot->file_size) }}@endif
                        &middot; {{ $snapshot->created_at->format('M j, Y') }}
                    </p>
                </div>
                <x-filament::button tag="a" size="sm" :color="$loop->first ? 'primary' : 'gray'" href="{{ route('design-snapshots.download', $snapshot) }}">
                    Download
                </x-filament::button>
            </li>
        @endforeach
    </ul>
@endif
