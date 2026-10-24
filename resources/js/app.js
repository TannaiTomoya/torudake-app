import { PasskeyExistsError, Passkeys, UserCancelledError } from '@laravel/passkeys';

const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(`--color-${name}`).trim();

// 社長の画面の三期比較。忙しい月は背景の薄い帯だけで示す（docs/03 §7.3）
const fontSize = (chart, wide, narrow) => ({ size: chart.width < 600 ? narrow : wide });

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
                        legend: {
                            position: 'bottom',
                            labels: { color: token('ink'), boxWidth: 14, font: (ctx) => fontSize(ctx.chart, 16, 13) },
                        },
                        tooltip: {
                            callbacks: {
                                label: (item) => `${item.dataset.label} ${item.raw === null ? '—' : `${Number(item.raw).toLocaleString('ja-JP')}円`}`,
                            },
                        },
                    },
                    scales: {
                        x: {
                            ticks: {
                                color: token('ink'),
                                autoSkip: false,
                                maxRotation: 0,
                                font: (ctx) => fontSize(ctx.chart, 16, 12),
                                callback(value) {
                                    const label = this.getLabelForValue(value);
                                    return this.chart.width < 600 ? label.replace('月', '') : label;
                                },
                            },
                            grid: { display: false },
                        },
                        y: {
                            ticks: {
                                color: token('ink-sub'),
                                maxTicksLimit: 5,
                                font: (ctx) => fontSize(ctx.chart, 14, 12),
                                callback: (v) => `${Math.round(v / 10000).toLocaleString('ja-JP')}万`,
                            },
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

    // 顔でログイン（パスキー）。ログイン画面のボタン
    window.Alpine.data('passkeyLogin', () => ({
        supported: Passkeys.isSupported(),
        busy: false,
        error: '',

        async login() {
            this.busy = true;
            this.error = '';

            try {
                const response = await Passkeys.verify();
                window.location.href = response.redirect ?? '/';
            } catch (e) {
                this.busy = false;
                if (!(e instanceof UserCancelledError)) {
                    this.error = 'うまくいきませんでした。もう一度押すか、下のパスワードで入ってください。';
                }
            }
        },
    }));

    // 顔でログイン（パスキー）。設定の画面で、この端末を登録する
    window.Alpine.data('passkeyRegister', () => ({
        supported: Passkeys.isSupported(),
        busy: false,
        error: '',
        name: '',

        async register() {
            this.busy = true;
            this.error = '';

            try {
                await Passkeys.register({ name: this.name.trim() || 'この端末' });
                this.name = '';
                await this.$wire.$refresh();
            } catch (e) {
                if (e instanceof PasskeyExistsError) {
                    this.error = 'この端末はもう登録されています。';
                } else if (!(e instanceof UserCancelledError)) {
                    this.error = '登録できませんでした。もう一度押してください。';
                }
            } finally {
                this.busy = false;
            }
        },
    }));
});
