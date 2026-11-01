// Phase 17: QR codes. The npm "qrcode" package (bundled by Vite, no CDN) draws each [data-qr] box as an SVG,
// so it stays sharp at any print size. Nothing is sent to a server: the box already holds the link.
//
//   <div id="qr-1" data-qr="https://school.example/login" data-qr-color="#1b3355" data-qr-label="..."></div>
//   <button data-qr-download="qr-1" data-filename="login-qr">Download</button>
import QRCode from 'qrcode';

const LIGHT = '#ffffff';   // always white: a light code on a dark card does not scan on many phones

async function draw(box) {
    const link = box.dataset.qr || '';
    if (link === '') return;

    try {
        // error correction M: about 15% of the code can be damaged or covered and it still scans
        box.innerHTML = await QRCode.toString(link, {
            type: 'svg',
            errorCorrectionLevel: 'M',
            margin: 2,
            color: { dark: box.dataset.qrColor || '#1b3355', light: LIGHT },
        });

        const svg = box.querySelector('svg');
        if (svg) {
            svg.setAttribute('role', 'img');
            svg.setAttribute('aria-label', box.dataset.qrLabel || 'QR code');
        }
    } catch (e) {
        box.textContent = 'This QR code could not be drawn. The link may be too long.';
    }
}

function download(button) {
    const box = document.getElementById(button.dataset.qrDownload || '');
    const svg = box ? box.querySelector('svg') : null;
    if (!svg) return;

    const blob = new Blob([new XMLSerializer().serializeToString(svg)], { type: 'image/svg+xml' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = (button.dataset.filename || 'qr-code') + '.svg';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

export default async function initQr() {
    await Promise.all(Array.from(document.querySelectorAll('[data-qr]')).map(draw));

    document.querySelectorAll('[data-qr-download]').forEach((button) => {
        button.addEventListener('click', () => download(button));
    });
}
