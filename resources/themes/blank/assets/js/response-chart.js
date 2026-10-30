// The measured-response chart of the design page (resources/themes/blank/components/design/response-chart.blade.php).
//
// An inline SVG drawn at the real width of its box, log frequency axis 20 Hz - 20 kHz. Below 560 px wide the plot is
// taller (0.8 x width) and has fewer frequency labels. Hover or drag a finger across it to read the summed level.
// Registered as Alpine.data('responseChart') in app.js. No user text goes into the SVG: names are shown by Alpine
// (x-text) in the legend buttons.

const LMIN = Math.log10(20);
const LSPAN = 3; // decades from 20 Hz to 20 kHz
const GRID = [20, 50, 100, 200, 500, 1000, 2000, 5000, 10000, 20000];
const NARROW_TICKS = [20, 100, 1000, 10000, 20000];
const MARGIN = { top: 8, right: 10, bottomLabels: 22, leftLabels: 34 };

const xOf = (f, w) => ((Math.log10(f) - LMIN) / LSPAN) * w;
const label = (f) => (f >= 1000 ? f / 1000 + 'k' : String(f));

export function formatHz(f) {
    return f >= 1000 ? (f / 1000).toFixed(f >= 10000 ? 0 : 1) + ' kHz' : Math.round(f) + ' Hz';
}

export default function responseChart(config) {
    // summed (default): one line per file plus their sum, and the readout is the sum. Not summed (several measurements
    // of one driver): the lines only, and the readout lists the level of each visible line.
    const summed = config.summed !== false;
    const lines = [
        ...config.series.map((s, i) => ({ key: 's' + i, label: s.label, color: s.color, width: summed ? 1.5 : 2, points: s.points })),
        ...(summed ? [{ key: 'sum', label: 'Sum', color: '#161b26', width: 2.4, points: config.sum }] : []),
    ];

    return {
        lines,
        off: {},
        at: null,
        geo: null,
        uid: 'sdc' + Math.random().toString(36).slice(2, 8),

        init() {
            this.draw();
            if (window.ResizeObserver) {
                let last = this.$refs.plot.clientWidth;
                new ResizeObserver(() => {
                    const w = this.$refs.plot.clientWidth;
                    if (Math.abs(w - last) > 4) {
                        last = w;
                        this.draw();
                    }
                }).observe(this.$refs.plot);
            }
        },

        toggle(key) {
            this.off[key] = !this.off[key];
            this.draw();
        },

        // the 40 dB window that holds the loudest point, in steps of 5 dB
        range() {
            const ys = lines.flatMap((l) => l.points.filter((p) => p[0] >= 20 && p[0] <= 20000).map((p) => p[1]));
            const hi = Math.ceil((Math.max(...ys) + 3) / 5) * 5;
            return [hi - 40, hi];
        },

        draw() {
            const box = this.$refs.plot;
            const w = Math.floor(box.clientWidth);
            if (!w) return;

            const narrow = w < 560;
            const h = narrow ? Math.round(w * 0.8) : Math.round(Math.min(380, Math.max(260, w * 0.34)));
            const [lo, hi] = this.range();
            const L = MARGIN.leftLabels, T = MARGIN.top, R = MARGIN.right;
            const iw = w - L - R, ih = h - T - MARGIN.bottomLabels;
            const yOf = (v) => T + ih - ((v - lo) / (hi - lo)) * ih;

            let grid = '', axis = '';
            for (const f of GRID) {
                const x = (L + xOf(f, iw)).toFixed(1);
                grid += `<line class="gridl" x1="${x}" x2="${x}" y1="${T}" y2="${T + ih}"/>`;
            }
            for (let v = lo; v <= hi; v += 10) {
                const y = yOf(v).toFixed(1);
                grid += `<line class="gridl" x1="${L}" x2="${L + iw}" y1="${y}" y2="${y}"/>`;
                axis += `<text x="${L - 6}" y="${(yOf(v) + 3).toFixed(1)}" text-anchor="end">${v}</text>`;
            }
            for (const f of narrow ? NARROW_TICKS : GRID) {
                axis += `<text x="${(L + xOf(f, iw)).toFixed(1)}" y="${h - 6}" text-anchor="${f === 20 ? 'start' : f === 20000 ? 'end' : narrow && f === 10000 ? 'end' : 'middle'}">${label(f)}</text>`;
            }

            let paths = '';
            for (const line of lines) {
                if (this.off[line.key]) continue;
                const d = line.points
                    .filter((p) => p[0] >= 20 && p[0] <= 20000)
                    .map((p, i) => (i ? 'L' : 'M') + (L + xOf(p[0], iw)).toFixed(1) + ' ' + yOf(p[1]).toFixed(1))
                    .join(' ');
                paths += `<path d="${d}" fill="none" stroke="${line.color}" stroke-width="${line.width}" stroke-linejoin="round" clip-path="url(#${this.uid})"/>`;
            }

            box.innerHTML =
                `<svg class="sd-chart" viewBox="0 0 ${w} ${h}" width="${w}" height="${h}" role="img" aria-label="Frequency response from 20 Hz to 20 kHz, ${lo} to ${hi} dB">` +
                `<defs><clipPath id="${this.uid}"><rect x="${L}" y="${T}" width="${iw}" height="${ih}"/></clipPath></defs>` +
                `${grid}<g class="axis">${axis}</g>${paths}` +
                `<line class="xhair" x1="0" x2="0" y1="${T}" y2="${T + ih}" stroke="#161b26" stroke-width="1" opacity="0"/>` +
                `<circle class="dotp" r="4" fill="#7c3fc2" opacity="0"/></svg>`;

            this.geo = { L, T, iw, ih, lo, hi, yOf };

            const svg = box.querySelector('svg');
            svg.style.touchAction = 'pan-y';
            svg.addEventListener('pointermove', (e) => this.read(e, svg));
            svg.addEventListener('pointerdown', (e) => this.read(e, svg));
        },

        // the summed level at the frequency under the pointer (the nearest point of the sum, on the log axis)
        read(e, svg) {
            const g = this.geo;
            const nearest = (pts, fq) => pts.reduce((a, b) => (Math.abs(Math.log(b[0] / fq)) < Math.abs(Math.log(a[0] / fq)) ? b : a));
            // the line the crosshair follows: the sum, or the first line that is switched on
            const lead = summed ? lines[lines.length - 1] : lines.find((l) => !this.off[l.key] && l.points.length);
            if (!g || !lead || !lead.points.length) return;

            const x = e.clientX - svg.getBoundingClientRect().left - g.L;
            if (x < 0 || x > g.iw) return;

            const fq = Math.pow(10, LMIN + (x / g.iw) * LSPAN);
            const p = nearest(lead.points, fq);
            const px = g.L + xOf(p[0], g.iw), py = g.yOf(Math.min(Math.max(p[1], g.lo), g.hi));

            const hair = svg.querySelector('.xhair'), dot = svg.querySelector('.dotp');
            hair.setAttribute('x1', px); hair.setAttribute('x2', px); hair.setAttribute('opacity', 0.35);
            dot.setAttribute('cx', px); dot.setAttribute('cy', py); dot.setAttribute('opacity', 1);

            if (summed) {
                this.at = { f: formatHz(p[0]), db: p[1].toFixed(1) + ' dB' };
            } else {
                const on = lines.filter((l) => !this.off[l.key] && l.points.length);
                this.at = {
                    f: formatHz(p[0]),
                    db: on.map((l) => (on.length > 1 ? l.label + ' ' : '') + nearest(l.points, fq)[1].toFixed(1) + ' dB').join(', '),
                };
            }
        },
    };
}
