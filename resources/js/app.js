const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(`--color-${name}`).trim();

// 社長の画面の三期比較。忙しい月は背景を薄く色づける（docs/03 §7.3）
document.addEventListener('alpine:init', () => {
    window.Alpine.data('ownerChart', (data) => ({
        chart: null,

        async init() {
            const { default: Chart } = await import('chart.js/auto');

            const busyShade = {
                id: 'busyShade',
                beforeDatasetsDraw(chart) {
                    const { ctx, chartArea, scales } = chart;
                    const width = (chartArea.right - chartArea.left) / data.labels.length;
                    ctx.save();
                    ctx.fillStyle = token('brand-soft');
                    data.busy.forEach((busy, i) => {
                        if (busy) {
                            ctx.fillRect(scales.x.getPixelForValue(i) - width / 2, chartArea.top, width, chartArea.bottom - chartArea.top);
                        }
                    });
                    ctx.restore();
                },
            };

            this.chart = new Chart(this.$refs.canvas, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: data.datasets.map((set) => ({
                        label: set.label,
                        data: set.values,
                        backgroundColor: token(set.color),
                    })),
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: { labels: { color: token('ink'), font: { size: 16 } } },
                        tooltip: {
                            callbacks: {
                                label: (item) => `${item.dataset.label} ${item.raw === null ? '—' : `${Number(item.raw).toLocaleString('ja-JP')}円`}`,
                            },
                        },
                    },
                    scales: {
                        x: { ticks: { color: token('ink'), font: { size: 16 } }, grid: { display: false } },
                        y: {
                            ticks: { color: token('ink-sub'), font: { size: 14 }, callback: (v) => `${Math.round(v / 10000).toLocaleString('ja-JP')}万円` },
                            grid: { color: token('line') },
                        },
                    },
                },
                plugins: [busyShade],
            });
        },

        destroy() {
            this.chart?.destroy();
        },
    }));
});
