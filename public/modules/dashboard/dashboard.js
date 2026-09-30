// Dashboard: draws its three charts with Chart.js from the figures in #dashboard-data, on the page's dark cards,
// and shows the year chosen as soon as it's chosen. Each chart's figures are also in a table beside it for screen
// readers, so the charts themselves are hidden from them.
(() => {
    const yearForm = document.querySelector('[data-year-form]');
    yearForm?.querySelector('select').addEventListener('change', () => yearForm.requestSubmit());

    const source = document.getElementById('dashboard-data');

    if (!source || typeof Chart === 'undefined') {
        return;
    }

    const figures = JSON.parse(source.textContent);
    const count = new Intl.NumberFormat('en-MY');

    // The cards' colours, as dashboard.css sets them.
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

    // A colour as rgba, from #rrggbb.
    const fade = (hex, alpha) => {
        const [r, g, b] = [1, 3, 5].map((start) => parseInt(hex.slice(start, start + 2), 16));
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    };

    // A fill fading down the chart, drawn once the chart knows where its plot area is.
    const fading = (from, to) => ({ chart }) => {
        const { ctx, chartArea } = chart;
        if (!chartArea) {
            return from;
        }
        const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
        gradient.addColorStop(0, from);
        gradient.addColorStop(1, to);
        return gradient;
    };

    const thousands = (value) => (value >= 1000 ? `${count.format(value / 1000)}k` : count.format(value));

    // Crime over the years: a smooth, shaded line for each category, the chosen year's points marked.
    const trend = figures.trend;
    const chosen = trend.years.indexOf(figures.year);

    new Chart(document.getElementById('trend-chart'), {
        type: 'line',
        data: {
            labels: trend.years,
            datasets: trend.series.map((series) => ({
                label: series.label,
                data: series.counts,
                borderColor: series.colour,
                backgroundColor: fading(fade(series.colour, 0.35), fade(series.colour, 0)),
                pointBackgroundColor: series.colour,
                pointBorderColor: colours.card,
                pointBorderWidth: 2,
                pointRadius: (context) => (context.dataIndex === chosen ? 5 : 0),
                pointHoverRadius: 6,
                borderWidth: 2.5,
                tension: 0.4,
                fill: 'origin',
            })),
        },
        options: {
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, border: { display: false }, ticks: { callback: thousands, maxTicksLimit: 6 } },
            },
            plugins: {
                tooltip: { callbacks: { label: (item) => ` ${item.dataset.label}: ${count.format(item.parsed.y)}` } },
            },
        },
    });

    // Crime by state: a rounded bar for each region, pink fading to orange, labelled with its short name.
    const regions = figures.regions;

    new Chart(document.getElementById('regions-chart'), {
        type: 'bar',
        data: {
            labels: regions.map((region) => region.short),
            datasets: [{
                label: t('All crime'),
                data: regions.map((region) => region.total),
                backgroundColor: fading('#f0628f', '#f7a35c'),
                hoverBackgroundColor: fading('#f58aab', '#f9b97f'),
                borderRadius: 999,
                borderSkipped: false,
                maxBarThickness: 12,
            }],
        },
        options: {
            maintainAspectRatio: false,
            scales: {
                // Upright, so all 16 short names fit under their bars.
                x: { grid: { display: false }, ticks: { autoSkip: false, minRotation: 90, maxRotation: 90, font: { size: 10 } } },
                y: { beginAtZero: true, border: { display: false }, ticks: { callback: thousands, maxTicksLimit: 6 } },
            },
            plugins: {
                tooltip: {
                    displayColors: false,
                    callbacks: {
                        title: ([item]) => regions[item.dataIndex].name,
                        label: (item) => `${t('All crime')}: ${count.format(item.parsed.y)}`,
                        afterLabel: (item) => Object.entries(regions[item.dataIndex].totals)
                            .map(([category, crimes]) => `${figures.categories[category]}: ${count.format(crimes)}`),
                    },
                },
            },
        },
    });

    // Crime by type: a ring of the largest types, its middle showing the total, or the slice pointed at.
    const types = figures.types;
    const centreCount = document.querySelector('[data-donut-count]');
    const centreLabel = document.querySelector('[data-donut-label]');

    const showCentre = (slice) => {
        centreCount.textContent = slice ? `${slice.share.toFixed(1)}%` : count.format(types.total);
        centreLabel.textContent = slice ? slice.label : t('crimes');
    };

    new Chart(document.getElementById('types-chart'), {
        type: 'doughnut',
        data: {
            labels: types.slices.map((slice) => slice.label),
            datasets: [{
                data: types.slices.map((slice) => slice.count),
                backgroundColor: types.slices.map((slice) => slice.colour),
                borderColor: colours.card,
                borderWidth: 3,
                borderRadius: 6,
                hoverOffset: 6,
            }],
        },
        options: {
            maintainAspectRatio: false,
            cutout: '70%',
            layout: { padding: 6 },
            onHover: (event, elements) => showCentre(elements.length ? types.slices[elements[0].index] : null),
            plugins: {
                tooltip: {
                    callbacks: { label: (item) => ` ${t(':count crimes', { count: count.format(item.parsed) })}` },
                },
            },
        },
    });

    document.getElementById('types-chart').addEventListener('mouseleave', () => showCentre(null));
})();
