{{-- Scoped to .saved-routes, so the page looks the same in the package's layout or inside an app's own. --}}
@once
<style>
    .saved-routes {
        --sr-text: #1d2330; --sr-muted: #5d6676; --sr-line: #dde1e7; --sr-card: #fff; --sr-field: #fff;
        --sr-accent: #2557d6; --sr-accent-text: #fff; --sr-danger: #c0392b; --sr-ok-bg: #e7f6ec; --sr-ok: #1d6b3a;
        --sr-error-bg: #fdecea; --sr-mark: #fff2a8; --sr-chip: #eef1f5;
        color: var(--sr-text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        max-width: 1200px; margin: 0 auto; padding: 24px 16px 48px; box-sizing: border-box;
    }
    @media (prefers-color-scheme: dark) {
        .saved-routes {
            --sr-text: #e6e9ef; --sr-muted: #9aa3b2; --sr-line: #2c313b; --sr-card: #1a1d23; --sr-field: #13161b;
            --sr-accent: #6d93ff; --sr-accent-text: #0d1117; --sr-danger: #ff7b6e; --sr-ok-bg: #16301f; --sr-ok: #8be0a8;
            --sr-error-bg: #3a1c19; --sr-mark: #5c4d00; --sr-chip: #262a33;
        }
    }
    .saved-routes *, .saved-routes *::before, .saved-routes *::after { box-sizing: border-box; }
    .saved-routes h1 { font-size: 26px; margin: 0 0 4px; }
    .saved-routes h2 { font-size: 18px; margin: 0 0 16px; }
    .saved-routes a { color: var(--sr-accent); }
    .saved-routes code { font: 13px/1.4 ui-monospace, SFMono-Regular, Consolas, monospace; }
    .saved-routes mark { background: var(--sr-mark); color: inherit; border-radius: 2px; }
    .saved-routes .sr-muted, .saved-routes .sr-hint { color: var(--sr-muted); }
    .saved-routes .sr-hint { font-size: 13px; display: block; margin-top: 4px; }
    .saved-routes .sr-head { margin-bottom: 20px; }
    .saved-routes .sr-head p { margin: 0; }
    .saved-routes .sr-alert { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
    .saved-routes .sr-alert-ok { background: var(--sr-ok-bg); color: var(--sr-ok); }
    .saved-routes .sr-alert-error { background: var(--sr-error-bg); color: var(--sr-danger); }
    .saved-routes .sr-layout { display: grid; gap: 20px; grid-template-columns: minmax(0, 1fr); align-items: start; }
    @media (min-width: 900px) { .saved-routes .sr-layout { grid-template-columns: 380px minmax(0, 1fr); } }
    .saved-routes .sr-card { background: var(--sr-card); border: 1px solid var(--sr-line); border-radius: 12px; padding: 20px; min-width: 0; }
    .saved-routes .sr-field { margin: 0 0 16px; padding: 0; border: 0; min-width: 0; }
    .saved-routes .sr-field > label, .saved-routes .sr-field > legend { display: block; font-weight: 600; margin-bottom: 6px; padding: 0; }
    .saved-routes input[type=text], .saved-routes input[type=search] {
        width: 100%; padding: 8px 10px; border: 1px solid var(--sr-line); border-radius: 8px;
        background: var(--sr-field); color: inherit; font: inherit;
    }
    .saved-routes .is-invalid { border-color: var(--sr-danger) !important; }
    .saved-routes .sr-error { display: block; color: var(--sr-danger); font-size: 13px; margin-top: 4px; }
    .saved-routes .sr-choices { display: flex; flex-wrap: wrap; gap: 6px 16px; }
    .saved-routes .sr-check { display: flex; align-items: center; gap: 6px; }
    .saved-routes .sr-roles { margin: 6px 0 0 24px; display: flex; flex-wrap: wrap; gap: 4px 16px; }
    .saved-routes .sr-actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
    .saved-routes .sr-btn {
        display: inline-flex; align-items: center; padding: 8px 14px; border-radius: 8px; border: 1px solid var(--sr-line);
        background: var(--sr-card); color: var(--sr-text); font: inherit; text-decoration: none; cursor: pointer;
    }
    .saved-routes .sr-btn-primary { background: var(--sr-accent); border-color: var(--sr-accent); color: var(--sr-accent-text); }
    .saved-routes .sr-btn-danger { color: var(--sr-danger); border-color: transparent; background: transparent; margin-right: auto; }
    .saved-routes .sr-btn-danger-solid { background: var(--sr-danger); border-color: var(--sr-danger); color: #fff; }
    .saved-routes .sr-btn-sm { padding: 4px 10px; font-size: 13px; }
    .saved-routes .sr-toolbar { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
    .saved-routes .sr-search { display: flex; gap: 8px; flex: 1 1 280px; }
    .saved-routes .sr-list { list-style: none; margin: 0; padding: 0; }
    .saved-routes .sr-row { display: flex; align-items: center; gap: 8px; border-top: 1px solid var(--sr-line); }
    .saved-routes .sr-row.is-current { background: var(--sr-chip); border-radius: 8px; }
    .saved-routes .sr-row-main {
        flex: 1; min-width: 0; display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px;
        padding: 10px 8px; color: inherit; text-decoration: none;
    }
    .saved-routes .sr-address { overflow-wrap: anywhere; }
    .saved-routes .sr-handler { color: var(--sr-muted); font-size: 13px; overflow-wrap: anywhere; }
    .saved-routes .sr-method { font: 600 11px/1 ui-monospace, Consolas, monospace; padding: 4px 6px; border-radius: 4px; background: var(--sr-chip); min-width: 52px; text-align: center; }
    .saved-routes .sr-badge { font-size: 12px; padding: 2px 8px; border-radius: 999px; background: var(--sr-chip); display: inline-flex; align-items: center; gap: 4px; }
    .saved-routes .sr-badge-danger { background: var(--sr-error-bg); color: var(--sr-danger); }
    .saved-routes .sr-empty { color: var(--sr-muted); margin: 8px 0; }
    .saved-routes .sr-details { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 6px 16px; margin: 16px 0; }
    .saved-routes .sr-details dt { color: var(--sr-muted); }
    .saved-routes .sr-details dd { margin: 0; overflow-wrap: anywhere; }
</style>
@endonce
