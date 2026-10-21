<a href="{{ route('cart') }}" class="relative flex items-center px-3 py-3 text-white transition duration-150 hover:text-accent-200" aria-label="Shopping cart, {{ $count }} {{ $count === 1 ? 'item' : 'items' }}">
    <x-phosphor-shopping-cart class="h-7 w-7" />
    <span data-cart-count class="{{ $count > 0 ? '' : 'hidden' }} absolute right-0 top-1 min-w-[1.25rem] rounded-full bg-accent-400 px-1 text-center text-xs font-bold leading-5 text-brand-950">{{ $count > 99 ? '99+' : $count }}</span>
</a>
