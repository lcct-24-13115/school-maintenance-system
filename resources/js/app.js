import Alpine from 'alpinejs';
import './alerts';

window.Alpine = Alpine;

Alpine.start();

// Charts (Merge Phase M4): Chart.js is bundled by Vite (no CDN) and loaded only on the reports page.
if (document.getElementById('report-charts')) {
    import('./reports').then((m) => m.default());
}
