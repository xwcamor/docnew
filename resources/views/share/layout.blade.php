<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', __('sharing.portal_title'))</title>
    <style>
        :root { --blue:#0A6ED1; --ink:#32363A; --muted:#6A6D70; --line:#e5e7eb; }
        * { box-sizing: border-box; }
        body { margin:0; background:#f4f6f8; font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color:var(--ink); }
        .wrap { max-width: 820px; margin: 0 auto; padding: 24px 16px 48px; }
        .topbar { background:#354A5F; }
        .topbar__in { max-width:820px; margin:0 auto; padding:14px 16px; display:flex; align-items:center; gap:12px; }
        .topbar img { max-height:34px; max-width:160px; }
        .topbar__name { color:#fff; font-weight:700; font-size:15px; }
        .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:22px; margin-bottom:16px; }
        .h1 { font-size:20px; font-weight:700; margin:0 0 6px; }
        .sub { color:var(--muted); font-size:13px; margin:0 0 18px; }
        .btn { background:var(--blue); color:#fff; border:none; border-radius:8px; padding:11px 20px; font-size:14px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
        .btn--ghost { background:#fff; color:var(--blue); border:1px solid var(--blue); }
        .input { width:100%; padding:11px 12px; border:1px solid var(--line); border-radius:8px; font-size:18px; letter-spacing:4px; text-align:center; }
        .field { margin-bottom:14px; }
        .alert { padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:14px; }
        .alert--ok { background:#e9f7ef; color:#1D7044; }
        .alert--err { background:#fdeceb; color:#C8281D; }
        .pill { display:inline-block; padding:3px 12px; border-radius:999px; color:#fff; font-weight:700; font-size:13px; }
        table.list { width:100%; border-collapse:collapse; }
        table.list th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); padding:8px 10px; border-bottom:2px solid var(--line); }
        table.list td { padding:10px; border-bottom:1px solid var(--line); font-size:14px; }
        .kpis { display:flex; flex-wrap:wrap; gap:18px; margin:14px 0; }
        .kpi b { display:block; font-size:18px; }
        .kpi span { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
        .dot { width:10px; height:10px; border-radius:50%; display:inline-block; vertical-align:middle; margin-right:6px; }
        .foot { text-align:center; color:#9aa0a6; font-size:12px; margin-top:22px; }
        .muted { color:var(--muted); }
        /* Detalle completo del diagnóstico */
        .h2 { font-size:16px; font-weight:700; margin:0 0 10px; color:#354A5F; }
        .narr { margin:0 0 14px; padding-left:18px; }
        .narr li { font-size:13px; line-height:1.55; color:#334155; margin-bottom:4px; }
        .tscroll { overflow-x:auto; }
        table.data td, table.data th { text-align:center; font-size:12.5px; padding:7px 6px; }
        table.data th small { display:block; font-weight:500; font-size:10px; color:#9aa0a6; }
        table.data td.over { background:#FCEBEA; color:#C8281D; font-weight:700; }
        .reco { background:#FFFBEB; border-left:4px solid #E9A23B; }
        .reco p { font-size:13px; line-height:1.55; color:#334155; margin:0 0 6px; }
        /* Flota: KPIs + toolbar (buscador, orden, filtro) */
        .kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:12px; margin:4px 0 18px; }
        .kpicard { border:1px solid var(--line); border-radius:10px; padding:12px 14px; text-align:center; }
        .kpicard b { display:block; font-size:24px; font-weight:700; line-height:1.1; }
        .kpicard span { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
        .chips { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 16px; }
        .chip { display:inline-flex; align-items:center; gap:7px; border:1px solid var(--line); background:#fff; border-radius:999px; padding:6px 13px; font-size:13px; cursor:pointer; user-select:none; }
        .chip[aria-pressed="true"] { border-color:#0A6ED1; box-shadow:0 0 0 1px #0A6ED1 inset; }
        .chip b { font-weight:700; }
        .chip .dot { width:10px; height:10px; }
        .toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:0 0 12px; }
        .toolbar .search { flex:1; min-width:180px; }
        .toolbar .search input { width:100%; padding:9px 12px; border:1px solid var(--line); border-radius:8px; font-size:14px; }
        .toolbar select { padding:9px 10px; border:1px solid var(--line); border-radius:8px; font-size:13px; background:#fff; }
        table.list th.sortable { cursor:pointer; user-select:none; white-space:nowrap; }
        table.list th.sortable:hover { color:#0A6ED1; }
        table.list th .arrow { opacity:.4; font-size:10px; }
        table.list th.sorted .arrow { opacity:1; color:#0A6ED1; }
        .hibar { display:inline-block; width:46px; height:7px; border-radius:4px; background:#eef0f2; overflow:hidden; vertical-align:middle; margin-right:7px; }
        .hibar i { display:block; height:100%; }
        .noresults { text-align:center; color:var(--muted); padding:24px; font-size:14px; }
    </style>
</head>
<body>
    <div class="topbar">
        <div class="topbar__in">
            @if (!empty($logo))
                <img src="{{ $logo }}" alt="">
            @else
                <span class="topbar__name">{{ config('app.name') }}</span>
            @endif
        </div>
    </div>
    <div class="wrap">
        @yield('content')
        <p class="foot">{{ __('sharing.portal_footer') }}</p>
    </div>
</body>
</html>
