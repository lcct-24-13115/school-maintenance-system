// Merge Phase M4: the charts on /reports. Only the Chart.js parts that are used are bundled.
import {
    Chart, ArcElement, BarElement, LineElement, PointElement,
    DoughnutController, BarController, LineController,
    CategoryScale, LinearScale, Tooltip, Legend, Filler,
} from 'chart.js';

Chart.register(
    ArcElement, BarElement, LineElement, PointElement,
    DoughnutController, BarController, LineController,
    CategoryScale, LinearScale, Tooltip, Legend, Filler,
);

const PALETTE = ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#65a30d', '#ea580c', '#475569'];
const colors = (n) => Array.from({ length: n }, (_, i) => PALETTE[i % PALETTE.length]);
const whole = { beginAtZero: true, ticks: { precision: 0 } };   // ticket counts are whole numbers

export default function initReports() {
    const root = document.getElementById('report-charts');
    if (!root) return;

    let charts;
    try {
        charts = JSON.parse(root.dataset.charts || '{}');
    } catch {
        return;
    }

    const draw = (id, config) => {
        const canvas = document.getElementById(id);
        const series = charts[id.replace('chart-', '')];
        if (!canvas || !series || series.data.length === 0) {
            canvas?.closest('[data-chart-card]')?.querySelector('[data-empty]')?.classList.remove('hidden');
            canvas?.classList.add('hidden');
            return;
        }
        new Chart(canvas, config(series));
    };

    const common = { responsive: true, maintainAspectRatio: false };

    draw('chart-status', (s) => ({
        type: 'doughnut',
        data: { labels: s.labels, datasets: [{ data: s.data, backgroundColor: colors(s.data.length) }] },
        options: { ...common, plugins: { legend: { position: 'bottom' } } },
    }));

    draw('chart-category', (s) => ({
        type: 'bar',
        data: { labels: s.labels, datasets: [{ label: 'Tickets', data: s.data, backgroundColor: '#2563eb' }] },
        options: { ...common, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: whole } },
    }));

    draw('chart-department', (s) => ({
        type: 'bar',
        data: { labels: s.labels, datasets: [{ label: 'Tickets', data: s.data, backgroundColor: '#16a34a' }] },
        options: { ...common, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: whole } },
    }));

    draw('chart-monthly', (s) => ({
        type: 'line',
        data: {
            labels: s.labels,
            datasets: [{ label: 'Tickets submitted', data: s.data, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.15)', fill: true, tension: 0.25 }],
        },
        options: { ...common, plugins: { legend: { display: false } }, scales: { y: whole } },
    }));
}
