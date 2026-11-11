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

    // Where the pointer is, a hairline down the chart at the nearest year, in a faint band, behind the lines; without
    // the pointer, the year chosen on the profile's slider ($picked).
    const crosshair = {
        id: 'crosshair',
        beforeDatasetsDraw(chart) {
            const active = chart.tooltip?.getActiveElements() ?? [];
            const at = active[0]?.element.x ?? chart.getDatasetMeta(0).data[chart.$picked ?? -1]?.x;

            if (at === undefined) {
                return;
            }

            const { ctx, chartArea } = chart;
            const x = Math.round(at);
            ctx.save();
            ctx.fillStyle = 'rgba(255, 255, 255, .04)';
            ctx.fillRect(x - 16, chartArea.top, 32, chartArea.bottom - chartArea.top);
            ctx.fillStyle = 'rgba(255, 255, 255, .3)';
            ctx.fillRect(x - 0.5, chartArea.top, 1, chartArea.bottom - chartArea.top);
            ctx.restore();
        },
    };

    // A profile's year slider and crime types. The slider takes the tiles, the crimes by type and the chart's band to any
    // year, the numbers counting and the bars sliding to it. A crime type, clicked, opens its own crimes each year, in
    // its category's colour, with how its change compares with the area's.
    function profileYears(yearsChart, wash) {
        const each = figures.eachYear ?? [];
        const percent = (share) => (share > 0 && share < 1 ? t('under 1%') : `${Math.round(share)}%`);
        const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // A figure counting from what it shows to what it should, a moment long.
        const counting = new Map();
        const countTo = (node, to) => {
            const from = Number(node.textContent.replace(/[^\d]/g, '')) || 0;
            cancelAnimationFrame(counting.get(node));
            if (still || from === to) {
                node.textContent = count.format(to);
                return;
            }
            const start = performance.now();
            const step = (now) => {
                const part = Math.min(1, Math.max(0, (now - start) / 500));
                node.textContent = count.format(Math.round(from + (to - from) * (1 - (1 - part) ** 3)));
                if (part < 1) {
                    counting.set(node, requestAnimationFrame(step));
                }
            };
            counting.set(node, requestAnimationFrame(step));
        };

        const pick = document.querySelector('[data-year-pick]');
        const slider = pick?.querySelector('input');
        const rows = [...document.querySelectorAll('.area-mix-row[data-type]')];

        const showYear = (index) => {
            const year = each[index];
            if (!year) {
                return;
            }

            yearsChart.$picked = index === each.length - 1 ? undefined : index;
            yearsChart.draw();
            document.querySelector('[data-year-heading]').textContent = t('In :year', { year: year.year });
            pick.querySelector('[data-year-shown]').textContent = year.year;
            slider.setAttribute('aria-valuetext', String(year.year));

            for (const category of ['assault', 'property']) {
                const figure = document.querySelector(`[data-figure="${category}"]`);
                if (figure) {
                    countTo(figure, year[category]);
                    document.querySelector(`[data-share="${category}"]`).textContent = t(':share of all', { share: percent(year.total > 0 ? (year[category] / year.total) * 100 : 0) });
                }
            }

            // The change since the first year, to this one: none to show for the first year itself.
            const since = document.querySelector('[data-since] strong');
            if (since && each[0].total > 0) {
                const change = ((year.total - each[0].total) / each[0].total) * 100;
                const size = Math.abs(change) < 0.5 && change !== 0 ? t('under 1%') : `${Math.round(Math.abs(change))}%`;
                since.textContent = index === 0 ? '–' : `${change < 0 ? '−' : '+'}${size}`;
            }

            const heading = document.querySelector('[data-mix-heading]');
            if (heading) {
                heading.textContent = t('Crimes by type in :year', { year: year.year });
            }
            const byType = new Map(year.mix.map((type) => [type.type, type]));
            for (const row of rows) {
                const type = byType.get(row.dataset.type) ?? { share: 0, areaShare: 0 };
                row.querySelector('.area-mix-fill').style.setProperty('--share', type.share);
                row.querySelector('.area-mix-mark').style.setProperty('--share', type.areaShare);
                row.querySelector('[data-here]').textContent = percent(type.share);
                row.querySelector('[data-there]').textContent = percent(type.areaShare);
            }

            // Most first in that year, as the page lists them for the latest, each row gliding to its new place.
            const order = [...rows].sort((a, b) => (byType.get(b.dataset.type)?.count ?? 0) - (byType.get(a.dataset.type)?.count ?? 0)
                || a.querySelector('.area-mix-pick').textContent.trim().localeCompare(b.querySelector('.area-mix-pick').textContent.trim()));
            const list = rows[0]?.parentElement;
            if (!list || order.every((row, at) => list.children[at] === row)) {
                return;
            }
            const was = new Map(rows.map((row) => [row, row.getBoundingClientRect().top]));
            list.append(...order);
            if (!still) {
                for (const row of rows) {
                    const by = was.get(row) - row.getBoundingClientRect().top;
                    if (by !== 0) {
                        row.animate([{ transform: `translateY(${by}px)` }, { transform: 'none' }], { duration: 450, easing: 'cubic-bezier(.2, .8, .2, 1)' });
                    }
                }
            }
        };

        if (pick && slider && each.length > 1) {
            pick.hidden = false;
            slider.addEventListener('input', () => showYear(Number(slider.value)));
        }

        // A crime type's own trend.
        const trend = document.querySelector('[data-type-trend]');
        if (!trend || rows.length === 0) {
            return;
        }
        document.querySelector('[data-type-hint]').hidden = false;
        let typeChart = null;
        let openRow = null;

        const close = () => {
            trend.hidden = true;
            openRow?.classList.remove('is-picked');
            openRow?.querySelector('.area-mix-pick').setAttribute('aria-expanded', 'false');
            openRow = null;
        };

        // "fell 40%", "rose 12%" or "stayed the same", as the profile's sentences say it.
        const moved = (from, to) => {
            const change = ((to - from) / from) * 100;
            const size = Math.abs(change) < 0.5 && change !== 0 ? t('under 1%') : `${Math.round(Math.abs(change))}%`;
            return change === 0 ? t('stayed the same') : t(change < 0 ? 'fell :size' : 'rose :size', { size });
        };

        const open = (row) => {
            if (openRow === row) {
                close();
                return;
            }
            close();
            openRow = row;
            row.classList.add('is-picked');
            row.querySelector('.area-mix-pick').setAttribute('aria-expanded', 'true');

            const type = row.dataset.type;
            const label = row.querySelector('.area-mix-pick').textContent.trim();
            const here = each.map((year) => year.mix.find((item) => item.type === type)?.count ?? 0);
            const there = each.map((year) => year.mix.find((item) => item.type === type)?.areaCount ?? 0);
            const colour = row.dataset.category === 'assault' ? '#e66767' : '#3987e5';
            const [first, last] = [each[0].year, each.at(-1).year];

            trend.querySelector('[data-type-heading]').textContent = t(':type each year in :district', { type: label, district: figures.district });
            trend.querySelector('[data-type-read]').textContent = here[0] > 0 && there[0] > 0
                ? t('From :from to :to, :type here :here, from :start to :end. Across :area it :there.', {
                    from: first, to: last, type: label.toLocaleLowerCase(), here: moved(here[0], here.at(-1)),
                    start: count.format(here[0]), end: count.format(here.at(-1)), area: figures.area, there: moved(there[0], there.at(-1)),
                })
                : t(':count in :to, from none in :from.', { count: count.format(here.at(-1)), to: last, from: first });
            trend.hidden = false;

            typeChart?.destroy();
            typeChart = new Chart(document.getElementById('area-type-chart'), {
                type: 'line',
                data: {
                    labels: figures.years,
                    datasets: [{
                        label, data: here, borderColor: colour, backgroundColor: wash(colour), fill: 'origin', borderWidth: 2,
                        cubicInterpolationMode: 'monotone', pointRadius: 3, pointHoverRadius: 5, pointBackgroundColor: colour,
                        pointBorderColor: colours.card, pointBorderWidth: 2, clip: 8,
                    }],
                },
                options: {
                    animation: still ? false : { duration: 600 },
                    maintainAspectRatio: false,
                    layout: { padding: { top: 8, right: 8 } },
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 5 } },
                    },
                    plugins: { tooltip: { callbacks: { label: (item) => ` ${label}: ${count.format(item.parsed.y)}` } } },
                },
                plugins: [crosshair],
            });
            trend.scrollIntoView({ behavior: still ? 'auto' : 'smooth', block: 'nearest' });
        };

        for (const row of rows) {
            row.querySelector('.area-mix-pick').addEventListener('click', () => open(row));
        }
        trend.querySelector('[data-type-close]').addEventListener('click', close);
    }

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

        const yearsChart = new Chart(document.getElementById('area-years-chart'), {
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
        });
        drawIn(yearsChart);
        profileYears(yearsChart, wash);
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

    const indexChart = new Chart(document.getElementById('area-index-chart'), {
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
    });
    drawIn(indexChart);

    // The year chosen on the crimes by type's slider (areas-types.js), marked here too; the latest, unmarked.
    document.addEventListener('area:year', (event) => {
        indexChart.$picked = event.detail.index === figures.years.length - 1 ? undefined : event.detail.index;
        indexChart.draw();
    });
})();
