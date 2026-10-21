{{-- Spring: a flowering vine along the bottom edge of the header. Inline SVG, no external assets. --}}
<svg xmlns="http://www.w3.org/2000/svg" class="season-garnish__edge" width="100%" height="24" aria-hidden="true" focusable="false">
    <defs>
        <g id="sg-blossom">
            <g fill="#fbcfe8" stroke="#f472b6" stroke-width=".5">
                <circle cx="0" cy="-3.1" r="2.3" />
                <circle cx="2.95" cy="-.96" r="2.3" />
                <circle cx="1.82" cy="2.5" r="2.3" />
                <circle cx="-1.82" cy="2.5" r="2.3" />
                <circle cx="-2.95" cy="-.96" r="2.3" />
            </g>
            <circle r="1.5" fill="#fde047" />
        </g>
        <pattern id="sg-spring" width="120" height="24" patternUnits="userSpaceOnUse">
            <path d="M0 8Q15 15 30 8T60 8T90 8T120 8" fill="none" stroke="#86efac" stroke-width="1.8" stroke-linecap="round" />
            <ellipse cx="30" cy="11" rx="4.5" ry="2" fill="#4ade80" transform="rotate(35 30 11)" />
            <ellipse cx="90" cy="11" rx="4.5" ry="2" fill="#4ade80" transform="rotate(35 90 11)" />
            <use href="#sg-blossom" x="15" y="14" transform="translate(0 0)" />
            <use href="#sg-blossom" x="75" y="14" />
            <use href="#sg-blossom" x="45" y="5" transform="translate(45 5) scale(.7) translate(-45 -5)" />
            <use href="#sg-blossom" x="105" y="5" transform="translate(105 5) scale(.7) translate(-105 -5)" />
        </pattern>
    </defs>
    <rect width="100%" height="24" fill="url(#sg-spring)" />
</svg>
