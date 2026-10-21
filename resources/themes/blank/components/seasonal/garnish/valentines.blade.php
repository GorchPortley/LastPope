{{-- Valentine's Day: a garland of small hearts along the bottom edge of the header. Inline SVG, no external assets. --}}
<svg xmlns="http://www.w3.org/2000/svg" class="season-garnish__edge" width="100%" height="24" aria-hidden="true" focusable="false">
    <defs>
        <path id="sg-heart" d="M0 4C-9-2-4-8 0-3.2 4-8 9-2 0 4z" />
        <pattern id="sg-valentines" width="150" height="24" patternUnits="userSpaceOnUse">
            <path d="M0 3Q37.5 17 75 3T150 3" fill="none" stroke="#fecdd3" stroke-width="1.2" />
            <use href="#sg-heart" x="37.5" y="11" fill="#fb7185" stroke="#fff1f2" stroke-width=".6" />
            <use href="#sg-heart" x="112.5" y="11" fill="#fb7185" stroke="#fff1f2" stroke-width=".6" />
            <use href="#sg-heart" x="75" y="8" fill="#fda4af" stroke="#fff1f2" stroke-width=".5" transform="translate(75 8) scale(.7) translate(-75 -8)" />
            <use href="#sg-heart" x="0" y="8" fill="#fda4af" stroke="#fff1f2" stroke-width=".5" transform="scale(.7) translate(0 3.4)" />
        </pattern>
    </defs>
    <rect width="100%" height="24" fill="url(#sg-valentines)" />
</svg>
