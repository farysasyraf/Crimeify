// "Check an area": draws the page's chart with Chart.js from the figures in #area-data, on the dark cards, as the
// dashboard does. A police district's profile has its property and violent crime each year, each a line over a faint
// wash of its colour; areas side by side have each one's crime each year against its first, all starting at 100, with
// Malaysia's as a dashed line. The lines draw themselves from left to right once the chart is in view, and a hairline
// follows the pointer to the nearest year. The figures are also in a table for screen readers, so the charts are
// hidden from them.
(() => {
    const source = document.getElementById('area-data');

    if (!source || typeof Chart === 'undefined') {
        return;
    }

    const figures = JSON.parse(source.textContent);
    const count = new Intl.NumberFormat('en-MY');
    const colours = { text: '#eef2f6', soft: '#b4bec8', rule: 'rgba(255, 255, 255, .08)', card: '#232529' };

    Chart.defaults.color = colours.soft;
    Chart.defaults.borderColor = colours.rule;
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.plugins.legend.display = false;
    Object.assign(Chart.defaults.plugins.tooltip, {
        backgroundColor: '#15171a',
        titleColor: colours.text,
        bodyColor: colours.text,
        borderColor: 'rgba(255, 255, 255, .12)',
        borderWidth: 1,
        padding: 10,
        cornerRadius: 10,
        boxPadding: 4,
        usePointStyle: true,
    });

    const thousands = (value) => (value >= 1000 ? `${count.format(value / 1000)}k` : count.format(value));

    // The lines draw themselves once the chart is in view: what's drawn is cut off at how far along they are, right of
    // the axis. At once for anyone whose system asks for less motion. Listed last of a chart's plugins, so whatever
    // the others draw with the lines, like their names, comes with them.
    const drawsItself = !matchMedia('(prefers-reduced-motion: reduce)').matches && 'IntersectionObserver' in window;
    const reveal = {
        id: 'reveal',
        beforeInit(chart) {
            chart.$reveal = drawsItself ? 0 : 1;
        },
        beforeDatasetsDraw(chart) {
            const { ctx, chartArea } = chart;
            ctx.save();
            ctx.beginPath();
            ctx.rect(0, 0, chartArea.left + (chart.width - chartArea.left) * chart.$reveal, chart.height);
            ctx.clip();
        },
        afterDatasetsDraw(chart) {
            chart.ctx.restore();
        },
    };
    const drawIn = (chart) => {
        if (!drawsItself) {
            return;
        }

        const watch = new IntersectionObserver(([entry]) => {
            if (!entry.isIntersecting) {
                return;
            }

            watch.disconnect();
            const start = performance.now() + 250;
            const frame = (now) => {
                const progress = Math.min(1, Math.max(0, (now - start) / 1200));
                chart.$reveal = 1 - (1 - progress) ** 3;
                chart.draw();

                if (progress < 1) {
                    requestAnimationFrame(frame);
                }
            };
            requestAnimationFrame(frame);
        }, { threshold: 0.4 });
        watch.observe(chart.canvas);
    };

    // Where the pointer is, a hairline down the chart at the nearest year, in a faint band, behind the lines.
    const crosshair = {
        id: 'crosshair',
        beforeDatasetsDraw(chart) {
            const active = chart.tooltip?.getActiveElements() ?? [];

            if (active.length === 0) {
                return;
            }

            const { ctx, chartArea } = chart;
            const x = Math.round(active[0].element.x);
            ctx.save();
            ctx.fillStyle = 'rgba(255, 255, 255, .04)';
            ctx.fillRect(x - 16, chartArea.top, 32, chartArea.bottom - chartArea.top);
            ctx.fillStyle = 'rgba(255, 255, 255, .3)';
            ctx.fillRect(x - 0.5, chartArea.top, 1, chartArea.bottom - chartArea.top);
            ctx.restore();
        },
    };

    if (figures.kind === 'profile') {
        // A line for each, never curving past a year's figure, over a wash of its colour that fades to nothing, with
        // a dot at its latest year. The tooltip gives both and the year's total.
        const last = figures.years.length - 1;
        const wash = (colour) => ({ chart }) => {
            if (!chart.chartArea) {
                return 'transparent';
            }

            const fill = chart.ctx.createLinearGradient(0, chart.chartArea.top, 0, chart.chartArea.bottom);
            fill.addColorStop(0, `${colour}26`);
            fill.addColorStop(1, `${colour}00`);
            return fill;
        };

        drawIn(new Chart(document.getElementById('area-years-chart'), {
            type: 'line',
            data: {
                labels: figures.years,
                datasets: figures.series.map((series) => ({
                    label: series.label,
                    data: series.counts,
                    borderColor: series.colour,
                    backgroundColor: wash(series.colour),
                    fill: 'origin',
                    borderWidth: 2,
                    cubicInterpolationMode: 'monotone',
                    pointRadius: ({ dataIndex }) => (dataIndex === last ? 4 : 0),
                    pointHoverRadius: 5,
                    pointHitRadius: 12,
                    pointBackgroundColor: series.colour,
                    pointHoverBackgroundColor: series.colour,
                    pointBorderColor: colours.card,
                    pointHoverBorderColor: colours.card,
                    pointBorderWidth: 2,
                    pointHoverBorderWidth: 2,
                    clip: 8,
                })),
            },
            options: {
                animation: false,
                maintainAspectRatio: false,
                layout: { padding: { top: 8, right: 8 } },
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, border: { display: false }, ticks: { callback: thousands, maxTicksLimit: 6 } },
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: (item) => ` ${item.dataset.label}: ${count.format(item.parsed.y)}`,
                            footer: (items) => `${t('All')}: ${count.format(items.reduce((sum, item) => sum + item.parsed.y, 0))}`,
                        },
                    },
                },
            },
            plugins: [crosshair, reveal],
        }));
        return;
    }

    // Areas side by side: a line for each, in its colour, named at its end, and Malaysia's dashed and grey behind them.
    // The tooltip gives each one's figure against its first year and its count.
    const gap = 15;
    const endLabels = {
        id: 'endLabels',
        afterDatasetsDraw(chart) {
            const { ctx, chartArea } = chart;

            // Each name beside its line's last point, moved apart where lines end close together, top to bottom, then
            // back up if the last went below the chart.
            const labels = chart.data.datasets
                .map((dataset, index) => ({ text: dataset.label, colour: dataset.borderColor, point: chart.getDatasetMeta(index).data.at(-1), last: dataset.data.at(-1) }))
                .filter((label) => label.point && label.last !== null)
                .map((label) => ({ ...label, y: label.point.y }))
                .sort((a, b) => a.y - b.y);
            labels.forEach((label, at) => {
                if (at > 0) {
                    label.y = Math.max(label.y, labels[at - 1].y + gap);
                }
            });
            for (let at = labels.length - 1; at >= 0; at--) {
                const limit = at === labels.length - 1 ? chartArea.bottom : labels[at + 1].y - gap;
                labels[at].y = Math.min(labels[at].y, limit);
            }

            // Each name in the text colour, after a dot in its line's, as the legend has them.
            ctx.save();
            ctx.font = `600 12px ${Chart.defaults.font.family}`;
            ctx.textBaseline = 'middle';
            labels.forEach((label) => {
                ctx.fillStyle = label.colour;
                ctx.beginPath();
                ctx.arc(label.point.x + 14, label.y, 4, 0, Math.PI * 2);
                ctx.fill();
                ctx.fillStyle = colours.text;
                ctx.fillText(label.text, label.point.x + 22, label.y);
            });
            ctx.restore();
        },
    };

    const lines = figures.series.map((series) => ({
        label: series.label,
        data: series.values,
        counts: series.counts,
        borderColor: series.colour,
        backgroundColor: series.colour,
        pointBackgroundColor: series.colour,
        pointBorderColor: colours.card,
        pointBorderWidth: 2,
        pointRadius: 4,
        pointHoverRadius: 6,
        borderWidth: 2,
        tension: 0.3,
    }));

    lines.push({
        label: figures.national.label,
        data: figures.national.values,
        borderColor: figures.national.colour,
        backgroundColor: figures.national.colour,
        borderDash: [5, 5],
        borderWidth: 1.5,
        pointRadius: 0,
        pointHoverRadius: 4,
        tension: 0.3,
        order: 1,
    });

    // Room at the right for the names at the lines' ends.
    const longest = Math.max(...lines.map((line) => line.label.length));

    drawIn(new Chart(document.getElementById('area-index-chart'), {
        type: 'line',
        data: { labels: figures.years, datasets: lines },
        options: {
            animation: false,
            maintainAspectRatio: false,
            layout: { padding: { right: Math.min(170, longest * 7 + 28) } },
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, border: { display: false }, ticks: { maxTicksLimit: 6 } },
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: (item) => {
                            const crimes = item.dataset.counts?.[item.dataIndex];
                            return ` ${item.dataset.label}: ${count.format(item.parsed.y)}${crimes === undefined ? '' : ` (${count.format(crimes)})`}`;
                        },
                    },
                },
            },
        },
        plugins: [crosshair, endLabels, reveal],
    }));
})();
