{{-- Halloween: small pumpkins hanging from the header on strings, with bats flying between them. Inline SVG. --}}
<svg xmlns="http://www.w3.org/2000/svg" class="season-garnish__edge" width="100%" height="24" aria-hidden="true" focusable="false">
    <defs>
        <g id="sg-pumpkin">
            <path d="M0 0v6" stroke="#a78bfa" stroke-width=".8" />
            <ellipse cx="-4" cy="14" rx="5" ry="6.5" fill="#ea580c" />
            <ellipse cx="4" cy="14" rx="5" ry="6.5" fill="#ea580c" />
            <ellipse cx="0" cy="14" rx="4" ry="7" fill="#fb923c" />
            <path d="M0 7.5q0-2 1.6-2.6" stroke="#4d7c0f" stroke-width="1.4" fill="none" stroke-linecap="round" />
            <path d="M-3.4 12.2l2 -1.6 .2 2.4zM3.4 12.2l-2 -1.6 -.2 2.4zM-3 16.4q3 2.4 6 0l-1 2.2h-4z" fill="#2e1065" />
        </g>
        <path id="sg-bat" d="M0 -2c-1-2-2.6-2.4-3.2-3.6.2 1.8-.6 2.8-1.8 3-1.6-1.8-3.8-2.2-6.2-1.2 1.8.6 2.6 1.8 2.8 3.2 1.2-.8 2.6-.6 3.6.6 1-.8 2.4-.6 3 .6.8-.8 1.8-.8 2.8-.2l.6 1.6z M0 -2c1-2 2.6-2.4 3.2-3.6-.2 1.8.6 2.8 1.8 3 1.6-1.8 3.8-2.2 6.2-1.2-1.8.6-2.6 1.8-2.8 3.2-1.2-.8-2.6-.6-3.6.6-1-.8-2.4-.6-3 .6-.8-.8-1.8-.8-2.8-.2L0 .6z" />
        <pattern id="sg-halloween" width="240" height="24" patternUnits="userSpaceOnUse">
            <use href="#sg-pumpkin" x="34" y="3" />
            <use href="#sg-bat" x="92" y="15" fill="#6d28d9" />
            <use href="#sg-pumpkin" x="154" y="1" transform="translate(0 0)" />
            <use href="#sg-bat" x="206" y="12" fill="#7c3aed" transform="translate(0 0)" />
        </pattern>
    </defs>
    <rect width="100%" height="24" fill="url(#sg-halloween)" />
</svg>
