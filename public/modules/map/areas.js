// "Check an area": draws the page's chart with Chart.js from the figures in #area-data, on the dark cards, as the
// dashboard does. A police district's profile has its crime each year, property and violent crime stacked; areas side
// by side have each one's crime each year against its first, all starting at 100, with Malaysia's as a dashed line.
// The figures are also in a table for screen readers, so the charts are hidden from them.
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

    if (figures.kind === 'profile') {
        // A bar for each year, property crime with violent crime on it, a gap of the card's colour between them, and
        // the top rounded. The tooltip gives both and the year's total.
        const last = figures.series.length - 1;

        new Chart(document.getElementById('area-years-chart'), {
            type: 'bar',
            data: {
                labels: figures.years,
                datasets: figures.series.map((series, at) => ({
                    label: series.label,
                    data: series.counts,
                    backgroundColor: series.colour,
                    borderColor: colours.card,
                    borderWidth: at === last ? 0 : { top: 2 },
                    borderSkipped: false,
                    borderRadius: at === last ? { topLeft: 4, topRight: 4 } : 0,
                    maxBarThickness: 44,
                })),
            },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, border: { display: false }, ticks: { callback: thousands, maxTicksLimit: 6 } },
                },
                plugins: {
                    tooltip: {
                        itemSort: (a, b) => b.datasetIndex - a.datasetIndex,
                        callbacks: {
                            label: (item) => ` ${item.dataset.label}: ${count.format(item.parsed.y)}`,
                            footer: (items) => `${t('All')}: ${count.format(items.reduce((sum, item) => sum + item.parsed.y, 0))}`,
                        },
                    },
                },
            },
        });
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

    new Chart(document.getElementById('area-index-chart'), {
        type: 'line',
        data: { labels: figures.years, datasets: lines },
        options: {
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
        plugins: [endLabels],
    });
})();
