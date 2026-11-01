// Merge Phase M4 (+ Phase 16: all-vs-active categories, daily bars): the charts on /reports. Only the Chart.js parts that are used are bundled.
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

// LCCT design-system colours (match public/css/style.css)
const PALETTE = ['#2F5D95', '#5E94CF', '#F2B705', '#c8372d', '#1f8a4c', '#6d4fc2', '#a8590a', '#1B3355', '#8795ab', '#bcd5ee'];
Chart.defaults.font.family = "'Nunito Sans', system-ui, sans-serif";
Chart.defaults.color = '#5b6b84';
// Phase 18: the same text colours as the status badges (public/css/phase6.css, .stx-*)
const STATUS_COLORS = {
    submitted: '#2F5D95', pending_authorization: '#d9a400', approved: '#4a55c4', assigned: '#17a2c9',
    in_progress: '#e6791a', work_completed: '#1f8a4c', closed: '#8795ab', disapproved: '#c8372d',
    waiting_for_user: '#8a6be0', escalated: '#c43d8a', cancelled: '#c3ccd8', reopened: '#14a094',
};
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

    // nothing to draw: no labels, or every value is 0 (a flat chart says nothing)
    const isEmpty = (series) => !series || !series.labels || series.labels.length === 0 || series.data.every((v) => v === 0);

    const draw = (id, config) => {
        const canvas = document.getElementById(id);
        const series = charts[id.replace('chart-', '')];
        if (!canvas || isEmpty(series)) {
            canvas?.closest('[data-chart-card]')?.querySelector('[data-empty]')?.classList.remove('hidden');
            canvas?.classList.add('hidden');
            return;
        }
        new Chart(canvas, config(series));
    };

    const common = { responsive: true, maintainAspectRatio: false };

    draw('chart-status', (s) => ({
        type: 'doughnut',
        data: { labels: s.labels, datasets: [{ data: s.data, backgroundColor: s.keys ? s.keys.map((k, i) => STATUS_COLORS[k] || PALETTE[i % PALETTE.length]) : colors(s.data.length), borderColor: '#fff', borderWidth: 2 }] },
        options: { ...common, plugins: { legend: { position: 'bottom' } } },
    }));

    // Phase 16: every ticket vs the ones still open, side by side
    draw('chart-category', (s) => ({
        type: 'bar',
        data: {
            labels: s.labels,
            datasets: [
                { label: 'All', data: s.data, backgroundColor: '#2F5D95', borderRadius: 6 },
                { label: 'Active (still open)', data: s.active || [], backgroundColor: '#F2B705', borderRadius: 6 },
            ],
        },
        options: { ...common, indexAxis: 'y', plugins: { legend: { position: 'bottom' } }, scales: { x: whole } },
    }));

    draw('chart-department', (s) => ({
        type: 'bar',
        data: { labels: s.labels, datasets: [{ label: 'Tickets', data: s.data, backgroundColor: '#5E94CF', borderRadius: 6 }] },
        options: { ...common, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: whole } },
    }));

    draw('chart-monthly', (s) => ({
        type: 'line',
        data: {
            labels: s.labels,
            datasets: [{ label: 'Tickets submitted', data: s.data, borderColor: '#2F5D95', backgroundColor: 'rgba(94,148,207,.18)', fill: true, tension: 0.25 }],
        },
        options: { ...common, plugins: { legend: { display: false } }, scales: { y: whole } },
    }));

    // Phase 16: tickets per day (vertical bars; with 30 labels only every few are written)
    draw('chart-daily', (s) => ({
        type: 'bar',
        data: { labels: s.labels, datasets: [{ label: 'Tickets submitted', data: s.data, backgroundColor: '#5E94CF', borderRadius: 4 }] },
        options: {
            ...common,
            plugins: { legend: { display: false } },
            scales: { y: whole, x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } } },
        },
    }));
}
