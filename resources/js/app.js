import Alpine from 'alpinejs';
import './alerts';

window.Alpine = Alpine;

Alpine.start();

// Charts (Merge Phase M4): Chart.js is bundled by Vite (no CDN) and loaded only on the reports page.
if (document.getElementById('report-charts')) {
    import('./reports').then((m) => m.default());
}

// QR codes (Phase 17): the npm "qrcode" library draws them in the browser. Loaded only on pages that have a [data-qr] box.
if (document.querySelector('[data-qr]')) {
    import('./qr').then((m) => m.default());
}
