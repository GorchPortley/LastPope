// Third-party browser libraries are bundled here with pinned versions (see package.json) instead of being
// loaded from CDNs at runtime.

// Phosphor icon font (`<i class="ph ph-...">`); only the regular weight is used by the templates.
import '@phosphor-icons/web/regular';

// Chart.js: the frequency / impedance viewers (resources/views/livewire/*-frequency-response-viewer.blade.php)
// use the global `Chart`.
import Chart from 'chart.js/auto';
window.Chart = Chart;

// Flowbite (data-attribute components: tabs, dropdowns, modals, carousels, ...). Auto-initialises on window load.
// Its stylesheet is copied to build/assets/flowbite.min.css by the plugin in vite.config.js (see partials/head.blade.php).
import 'flowbite';

// Alpine plugins must be registered on the Alpine instance Livewire 3 ships (window.Alpine); do not import a
// second copy of Alpine here.
import focus from '@alpinejs/focus';
import responseChart from './response-chart';
document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(focus);
    // the measured-response chart of the design page
    window.Alpine.data('responseChart', responseChart);
});

window.sidebarLinkDemo = function(event){
    event.preventDefault(); 
    new FilamentNotification().title('Modify this button inside of sidebar.blade.php').send();
}
