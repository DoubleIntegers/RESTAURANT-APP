<?php
// ============================================================
//  API HANDLER — dipanggil via AJAX (?action=api)
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'api') {
    header('Content-Type: application/json');
    require_once 'config.php';
    try {
        $client     = new MongoDB\Client(MONGO_URI);
        $collection = $client->selectCollection(MONGO_DB, MONGO_COLLECTION);

        // Meta: daftar borough untuk dropdown
        if (isset($_GET['meta'])) {
            $boroughs = $collection->distinct('borough', []);
            sort($boroughs);
            echo json_encode(['boroughs' => $boroughs]);
            exit;
        }

        // ── Input filter ──────────────────────────────────────
        $borough  = isset($_GET['borough']) ? trim($_GET['borough']) : '';
        $cuisine  = isset($_GET['cuisine']) ? trim($_GET['cuisine']) : '';
        $scoreVal = isset($_GET['score_val']) && $_GET['score_val'] !== '' ? (int)$_GET['score_val'] : null;
        $scoreOp  = isset($_GET['score_op'])  ? $_GET['score_op'] : 'lte';
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $perPage  = 20;
        $skip     = ($page - 1) * $perPage;

        // ── Build filter ──────────────────────────────────────
        $filter  = [];
        $opMap   = ['lt'=>'$lt','lte'=>'$lte','eq'=>'$eq','gte'=>'$gte','gt'=>'$gt'];
        $mongoOp = $opMap[$scoreOp] ?? '$lte';

        if ($borough !== '' && $borough !== 'All')
            $filter['borough'] = $borough;

        if ($cuisine !== '')
            $filter['cuisine'] = new MongoDB\BSON\Regex(preg_quote($cuisine, '/'), 'i');

        if ($scoreVal !== null)
            $filter['$expr'] = [$mongoOp => [['$arrayElemAt' => ['$grades.score', 0]], $scoreVal]];

        // ── Query ─────────────────────────────────────────────
        $total  = $collection->countDocuments($filter);
        $cursor = $collection->find($filter, [
            'skip'       => $skip,
            'limit'      => $perPage,
            'sort'       => ['name' => 1],
            'projection' => [
                '_id'           => 0,
                'restaurant_id' => 1,
                'name'          => 1,
                'borough'       => 1,
                'cuisine'       => 1,
                'address'       => 1,
                'grades'        => 1,
            ],
        ]);

        $restaurants = [];
        foreach ($cursor as $doc) {
            $arr = iterator_to_array($doc);
            if (isset($arr['grades'])) {
                foreach ($arr['grades'] as &$g) {
                    if ($g['date'] instanceof MongoDB\BSON\UTCDateTime)
                        $g['date'] = $g['date']->toDateTime()->format('Y-m-d');
                }
                unset($g);
            }
            $restaurants[] = $arr;
        }

        echo json_encode([
            'total'       => $total,
            'page'        => $page,
            'perPage'     => $perPage,
            'totalPages'  => (int)ceil($total / $perPage),
            'restaurants' => $restaurants,
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Restaurant Explorer</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg:#0d0f14; --surface:#161920; --surface2:#1e222d;
    --border:#2a2f3f; --border2:#353c52;
    --accent:#00e5a0; --accent2:#00b87d; --accent-dim:rgba(0,229,160,.12);
    --yellow:#f5c842; --red:#ff5a5a; --blue:#4da6ff; --purple:#b47cff;
    --text:#e8ecf5; --muted:#7a8199; --muted2:#555e78;
    --radius:8px; --radius-lg:14px;
    --mono:'JetBrains Mono',monospace; --sans:'Syne',sans-serif;
    --shadow:0 4px 24px rgba(0,0,0,.5);
  }
  html { font-size:14px; }
  body { background:var(--bg); color:var(--text); font-family:var(--sans); min-height:100vh; display:flex; flex-direction:column; }

  /* Topbar */
  .topbar { background:var(--surface); border-bottom:1px solid var(--border); padding:0 24px; height:52px; display:flex; align-items:center; gap:16px; flex-shrink:0; position:sticky; top:0; z-index:100; }
  .topbar-logo { display:flex; align-items:center; gap:10px; font-family:var(--mono); font-size:13px; font-weight:600; color:var(--accent); }
  .topbar-sep { width:1px; height:24px; background:var(--border2); margin:0 4px; }
  .topbar-path { font-family:var(--mono); font-size:11.5px; color:var(--muted); display:flex; align-items:center; gap:6px; }
  .topbar-path span { color:var(--text); }
  .topbar-right { margin-left:auto; display:flex; align-items:center; gap:12px; }
  .badge-count { background:var(--accent-dim); color:var(--accent); border:1px solid rgba(0,229,160,.25); border-radius:20px; font-family:var(--mono); font-size:11px; padding:2px 10px; font-weight:600; }

  /* Layout */
  .layout { display:flex; flex:1; overflow:hidden; height:calc(100vh - 52px); }

  /* Sidebar */
  .sidebar { width:280px; flex-shrink:0; background:var(--surface); border-right:1px solid var(--border); display:flex; flex-direction:column; overflow-y:auto; }
  .sidebar-section { border-bottom:1px solid var(--border); padding:18px 18px 20px; }
  .sidebar-section:last-child { border-bottom:none; }
  .section-label { font-family:var(--mono); font-size:10px; letter-spacing:1.4px; text-transform:uppercase; color:var(--muted); margin-bottom:12px; display:flex; align-items:center; gap:7px; }
  .section-label::after { content:''; flex:1; height:1px; background:var(--border); }
  .filter-group { margin-bottom:14px; }
  .filter-group:last-child { margin-bottom:0; }
  .filter-label { font-size:11px; font-weight:600; color:var(--muted); letter-spacing:.5px; margin-bottom:6px; display:block; }
  .filter-select, .filter-input { width:100%; background:var(--surface2); border:1px solid var(--border2); border-radius:var(--radius); color:var(--text); font-family:var(--mono); font-size:12px; padding:8px 12px; outline:none; transition:border-color .18s,box-shadow .18s; appearance:none; -webkit-appearance:none; }
  .filter-select { background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%237a8199' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 10px center; padding-right:28px; }
  .filter-select:focus,.filter-input:focus { border-color:var(--accent2); box-shadow:0 0 0 3px rgba(0,229,160,.1); }
  .filter-select option { background:var(--surface2); }

  /* Operator buttons */
  .operator-row { display:flex; gap:5px; margin-bottom:8px; }
  .op-btn { flex:1; background:var(--surface2); border:1px solid var(--border2); color:var(--muted); font-family:var(--mono); font-size:12px; padding:6px 2px; border-radius:var(--radius); cursor:pointer; transition:all .14s; text-align:center; }
  .op-btn:hover { border-color:var(--border); color:var(--text); }
  .op-btn.active { background:var(--accent-dim); border-color:var(--accent2); color:var(--accent); font-weight:700; }

  .score-row { display:flex; align-items:center; gap:8px; }
  input[type=range] { -webkit-appearance:none; width:100%; height:4px; background:var(--border2); border-radius:2px; outline:none; margin-top:8px; }
  input[type=range]::-webkit-slider-thumb { -webkit-appearance:none; width:14px; height:14px; border-radius:50%; background:var(--accent); cursor:pointer; border:2px solid var(--bg); }

  .btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; padding:8px 16px; border-radius:var(--radius); font-family:var(--mono); font-size:12px; font-weight:600; cursor:pointer; border:none; outline:none; transition:all .16s; }
  .btn-primary { background:var(--accent); color:#0a1a12; width:100%; padding:10px; margin-top:4px; }
  .btn-primary:hover { background:#00ffb3; box-shadow:0 0 16px rgba(0,229,160,.4); }
  .btn-ghost { background:transparent; color:var(--muted); border:1px solid var(--border2); width:100%; margin-top:6px; }
  .btn-ghost:hover { border-color:var(--border); color:var(--text); background:var(--surface2); }

  .stat-item { display:flex; justify-content:space-between; align-items:center; padding:6px 0; border-bottom:1px solid var(--border); font-size:12px; }
  .stat-item:last-child { border-bottom:none; }
  .stat-item .stat-label { color:var(--muted); }
  .stat-item .stat-val { font-family:var(--mono); color:var(--accent); font-weight:600; }

  /* Main */
  .main { flex:1; display:flex; flex-direction:column; overflow:hidden; }
  .toolbar { background:var(--surface); border-bottom:1px solid var(--border); padding:10px 20px; display:flex; align-items:center; gap:12px; flex-shrink:0; overflow:hidden; }
  .toolbar-info { font-family:var(--mono); font-size:11.5px; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:1; }
  .view-toggle { display:flex; gap:2px; flex-shrink:0; }
  .view-btn { background:var(--surface2); border:1px solid var(--border2); color:var(--muted); padding:6px 10px; cursor:pointer; font-size:13px; transition:all .14s; }
  .view-btn:first-child { border-radius:var(--radius) 0 0 var(--radius); }
  .view-btn:last-child  { border-radius:0 var(--radius) var(--radius) 0; }
  .view-btn.active { background:var(--accent-dim); border-color:var(--accent2); color:var(--accent); }

  .results { flex:1; overflow-y:auto; padding:16px 20px; }
  .results::-webkit-scrollbar { width:6px; }
  .results::-webkit-scrollbar-track { background:var(--bg); }
  .results::-webkit-scrollbar-thumb { background:var(--border2); border-radius:3px; }

  /* Table */
  .table-wrap { overflow-x:auto; }
  table { width:100%; border-collapse:collapse; font-size:12.5px; }
  thead th { background:var(--surface2); color:var(--muted); font-family:var(--mono); font-size:10.5px; letter-spacing:1px; text-transform:uppercase; padding:10px 14px; text-align:left; border-bottom:2px solid var(--border2); white-space:nowrap; position:sticky; top:0; }
  tbody tr { border-bottom:1px solid var(--border); transition:background .1s; cursor:pointer; }
  tbody tr:hover { background:var(--surface2); }
  tbody td { padding:9px 14px; vertical-align:middle; font-family:var(--mono); font-size:12px; }
  .td-name { color:var(--text); font-weight:600; max-width:220px; }
  .td-id   { color:var(--muted2); font-size:11px; }
  .td-cuisine { color:var(--muted); }
  .td-address { color:var(--muted2); font-size:11px; max-width:180px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

  /* Cards */
  .card-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:14px; }
  .card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:16px; cursor:pointer; transition:border-color .16s,transform .16s,box-shadow .16s; position:relative; overflow:hidden; }
  .card::before { content:''; position:absolute; top:0;left:0;right:0; height:2px; background:linear-gradient(90deg,var(--accent),transparent); opacity:0; transition:opacity .16s; }
  .card:hover { border-color:var(--border2); transform:translateY(-2px); box-shadow:var(--shadow); }
  .card:hover::before { opacity:1; }
  .card-name { font-size:14px; font-weight:700; color:var(--text); line-height:1.3; margin-bottom:2px; }
  .card-id { font-family:var(--mono); font-size:10px; color:var(--muted2); margin-bottom:10px; }
  .card-meta { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px; }
  .tag { display:inline-flex; align-items:center; font-family:var(--mono); font-size:10.5px; padding:3px 9px; border-radius:20px; font-weight:500; white-space:nowrap; }
  .tag-borough { background:rgba(77,166,255,.12); color:var(--blue); border:1px solid rgba(77,166,255,.2); }
  .tag-cuisine { background:rgba(181,124,255,.12); color:var(--purple); border:1px solid rgba(181,124,255,.2); }
  .card-address { font-family:var(--mono); font-size:11px; color:var(--muted); margin-bottom:12px; }
  .grades-row { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
  .grade-chip { display:flex; align-items:center; gap:5px; font-family:var(--mono); font-size:11px; padding:4px 8px; border-radius:6px; background:var(--surface2); border:1px solid var(--border2); }
  .grade-letter { font-weight:700; font-size:12px; }
  .grade-A{color:var(--accent)} .grade-B{color:var(--yellow)} .grade-C{color:var(--red)} .grade-Z{color:var(--muted)} .grade-P{color:var(--blue)}
  .grade-score { color:var(--muted); font-size:10.5px; }
  .grade-latest { border-color:rgba(0,229,160,.3); background:var(--accent-dim); }

  /* Pagination */
  .pagination { display:flex; align-items:center; justify-content:center; gap:6px; padding:16px 20px; border-top:1px solid var(--border); flex-shrink:0; background:var(--surface); }
  .page-btn { background:var(--surface2); border:1px solid var(--border2); color:var(--muted); font-family:var(--mono); font-size:12px; padding:6px 12px; border-radius:var(--radius); cursor:pointer; transition:all .14s; min-width:36px; text-align:center; }
  .page-btn:hover:not(:disabled) { border-color:var(--accent2); color:var(--accent); background:var(--accent-dim); }
  .page-btn.active { background:var(--accent); color:#0a1a12; border-color:var(--accent); font-weight:700; }
  .page-btn:disabled { opacity:.35; cursor:not-allowed; }
  .page-info { font-family:var(--mono); font-size:11.5px; color:var(--muted); padding:0 8px; }

  /* Modal */
  .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:200; backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:24px; }
  .modal-overlay.open { display:flex; }
  .modal { background:var(--surface); border:1px solid var(--border2); border-radius:var(--radius-lg); width:100%; max-width:640px; max-height:80vh; overflow-y:auto; box-shadow:0 24px 80px rgba(0,0,0,.6); animation:slideUp .2s ease; }
  @keyframes slideUp { from{transform:translateY(20px);opacity:0} to{transform:translateY(0);opacity:1} }
  .modal-header { padding:20px 24px 16px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:flex-start; position:sticky; top:0; background:var(--surface); }
  .modal-title { font-size:17px; font-weight:700; }
  .modal-close { background:var(--surface2); border:1px solid var(--border2); color:var(--muted); width:28px; height:28px; border-radius:6px; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:16px; flex-shrink:0; transition:all .14s; }
  .modal-close:hover { color:var(--text); border-color:var(--border); }
  .modal-body { padding:20px 24px; }
  .modal-field { margin-bottom:16px; }
  .modal-field-label { font-family:var(--mono); font-size:10px; letter-spacing:1.2px; text-transform:uppercase; color:var(--muted); margin-bottom:5px; }
  .modal-field-val { font-family:var(--mono); font-size:13px; }
  .grades-table { width:100%; border-collapse:collapse; font-family:var(--mono); font-size:12px; }
  .grades-table th { color:var(--muted); font-size:10px; letter-spacing:.8px; text-transform:uppercase; padding:6px 10px; text-align:left; background:var(--surface2); border-bottom:1px solid var(--border2); }
  .grades-table td { padding:7px 10px; border-bottom:1px solid var(--border); }
  .grades-table tr:last-child td { border-bottom:none; }
  .grades-table tr:first-child td { background:var(--accent-dim); }

  /* States */
  .state-center { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:14px; padding:60px 20px; color:var(--muted); font-family:var(--mono); font-size:13px; }
  .spinner { width:32px; height:32px; border:3px solid var(--border2); border-top-color:var(--accent); border-radius:50%; animation:spin .7s linear infinite; }
  @keyframes spin { to{transform:rotate(360deg)} }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-logo">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4.03 3-9 3S3 13.66 3 12"/><path d="M3 5v14c0 1.66 4.03 3 9 3s9-1.34 9-3V5"/></svg>
    Restaurant Explorer
  </div>
  <div class="topbar-sep"></div>
  <div class="topbar-path">restaurantdb › <span>restaurants</span></div>
  <div class="topbar-right">
    <span class="badge-count" id="total-badge">— docs</span>
  </div>
</div>

<div class="layout">
  <aside class="sidebar">
    <div class="sidebar-section">
      <div class="section-label">Filter</div>

      <div class="filter-group">
        <label class="filter-label">Borough</label>
        <select class="filter-select" id="filter-borough">
          <option value="">All Boroughs</option>
        </select>
      </div>

      <div class="filter-group">
        <label class="filter-label">Cuisine (keyword)</label>
        <input type="text" class="filter-input" id="filter-cuisine" placeholder="e.g. Italian, Chinese…">
      </div>

      <div class="filter-group">
        <label class="filter-label">Last Grade Score</label>
        <div class="operator-row">
          <button class="op-btn" data-op="lt">&lt;</button>
          <button class="op-btn active" data-op="lte">≤</button>
          <button class="op-btn" data-op="eq">=</button>
          <button class="op-btn" data-op="gte">≥</button>
          <button class="op-btn" data-op="gt">&gt;</button>
        </div>
        <div class="score-row">
          <input type="number" class="filter-input" id="filter-score-num" placeholder="Any" min="0" max="200">
          <span style="color:var(--muted);font-family:var(--mono);font-size:11px;flex-shrink:0">or drag</span>
        </div>
        <input type="range" id="filter-score-range" min="0" max="100" value="100">
        <div style="display:flex;justify-content:space-between;margin-top:4px">
          <span style="font-family:var(--mono);font-size:10px;color:var(--muted2)">0</span>
          <span style="font-family:var(--mono);font-size:10px;color:var(--muted2)">100+</span>
        </div>
      </div>

      <button class="btn btn-primary" id="btn-search">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        Run Query
      </button>
      <button class="btn btn-ghost" id="btn-reset">Reset Filters</button>
    </div>

    <div class="sidebar-section">
      <div class="section-label">Stats</div>
      <div class="stat-item"><span class="stat-label">Total results</span><span class="stat-val" id="stat-total">—</span></div>
      <div class="stat-item"><span class="stat-label">Current page</span><span class="stat-val" id="stat-page">—</span></div>
      <div class="stat-item"><span class="stat-label">Showing</span><span class="stat-val" id="stat-showing">—</span></div>
    </div>
  </aside>

  <div class="main">
    <div class="toolbar">
      <span class="toolbar-info" id="toolbar-query">db.restaurants.find(<span style="color:var(--accent)">{}</span>)</span>
      <div class="view-toggle">
        <button class="view-btn active" id="view-table" title="Table view">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/></svg>
        </button>
        <button class="view-btn" id="view-cards" title="Card view">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        </button>
      </div>
    </div>

    <div class="results" id="results">
      <div class="state-center"><div class="spinner"></div><span>Connecting to MongoDB…</span></div>
    </div>
    <div class="pagination" id="pagination" style="display:none;"></div>
  </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="modal-overlay">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="modal-title"></div>
        <div style="font-family:var(--mono);font-size:11px;color:var(--muted);margin-top:4px" id="modal-id"></div>
      </div>
      <button class="modal-close" id="modal-close">✕</button>
    </div>
    <div class="modal-body" id="modal-body"></div>
  </div>
</div>

<script>
let currentPage = 1, currentView = 'table', currentOp = 'lte', lastData = null;

document.addEventListener('DOMContentLoaded', () => {
  loadBoroughs();
  runQuery(1);

  document.getElementById('btn-search').addEventListener('click', () => runQuery(1));
  document.getElementById('btn-reset').addEventListener('click', resetFilters);

  document.querySelectorAll('.op-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.op-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      currentOp = btn.dataset.op;
    });
  });

  const numEl = document.getElementById('filter-score-num');
  const rngEl = document.getElementById('filter-score-range');
  numEl.addEventListener('input', () => { if (numEl.value !== '') rngEl.value = Math.min(numEl.value, 100); });
  rngEl.addEventListener('input', () => { numEl.value = rngEl.value == 100 ? '' : rngEl.value; });

  document.getElementById('filter-cuisine').addEventListener('keydown', e => { if(e.key==='Enter') runQuery(1); });
  numEl.addEventListener('keydown', e => { if(e.key==='Enter') runQuery(1); });

  document.getElementById('view-table').addEventListener('click', () => setView('table'));
  document.getElementById('view-cards').addEventListener('click', () => setView('cards'));
  document.getElementById('modal-close').addEventListener('click', closeModal);
  document.getElementById('modal-overlay').addEventListener('click', e => { if(e.target.id==='modal-overlay') closeModal(); });
});

async function loadBoroughs() {
  try {
    const data = await fetch('index.php?action=api&meta=1').then(r=>r.json());
    const sel  = document.getElementById('filter-borough');
    (data.boroughs||[]).forEach(b => {
      const o = document.createElement('option');
      o.value = b; o.textContent = b; sel.appendChild(o);
    });
  } catch(e) {}
}

function buildUrl(page) {
  const borough  = document.getElementById('filter-borough').value;
  const cuisine  = document.getElementById('filter-cuisine').value.trim();
  const scoreVal = document.getElementById('filter-score-num').value.trim();
  const p = new URLSearchParams({ action:'api', page });
  if (borough)   p.set('borough', borough);
  if (cuisine)   p.set('cuisine', cuisine);
  if (scoreVal)  { p.set('score_val', scoreVal); p.set('score_op', currentOp); }
  return 'index.php?' + p;
}

async function runQuery(page) {
  currentPage = page; showLoading();
  try {
    const data = await fetch(buildUrl(page)).then(r=>r.json());
    if (data.error) { showError(data.error); return; }
    lastData = data;
    updateStats(data); updateToolbar(); renderResults(data); renderPagination(data);
  } catch(e) { showError('Tidak bisa konek ke server PHP.'); }
}

function updateToolbar() {
  const borough  = document.getElementById('filter-borough').value;
  const cuisine  = document.getElementById('filter-cuisine').value.trim();
  const scoreVal = document.getElementById('filter-score-num').value.trim();
  const opMap    = {lt:'$lt',lte:'$lte',eq:'$eq',gte:'$gte',gt:'$gt'};
  const parts    = [];
  if (borough)   parts.push(`<span style="color:var(--blue)">"borough"</span>: <span style="color:var(--yellow)">"${borough}"</span>`);
  if (cuisine)   parts.push(`<span style="color:var(--blue)">"cuisine"</span>: <span style="color:var(--yellow)">/${cuisine}/i</span>`);
  if (scoreVal)  parts.push(`<span style="color:var(--blue)">"score"</span>: {<span style="color:var(--purple)">${opMap[currentOp]}</span>: <span style="color:var(--accent)">${scoreVal}</span>}`);
  const q = parts.length ? `{ ${parts.join(', ')} }` : `<span style="color:var(--accent)">{}</span>`;
  document.getElementById('toolbar-query').innerHTML = `db.restaurants.find(${q})`;
}

function updateStats(d) {
  document.getElementById('stat-total').textContent   = d.total.toLocaleString();
  document.getElementById('stat-page').textContent    = `${d.page} / ${d.totalPages}`;
  const from = (d.page-1)*d.perPage+1, to = Math.min(d.page*d.perPage, d.total);
  document.getElementById('stat-showing').textContent = `${from}–${to}`;
  document.getElementById('total-badge').textContent  = d.total.toLocaleString()+' docs';
}

function renderResults(d) {
  if (!d.restaurants.length) {
    document.getElementById('results').innerHTML = `<div class="state-center"><div style="font-size:40px;opacity:.4">🍽</div><span>No restaurants match your filters.</span></div>`;
    document.getElementById('pagination').style.display = 'none';
    return;
  }
  currentView === 'table' ? renderTable(d.restaurants) : renderCards(d.restaurants);
}

function setView(v) {
  currentView = v;
  document.getElementById('view-table').classList.toggle('active', v==='table');
  document.getElementById('view-cards').classList.toggle('active', v==='cards');
  if (lastData) renderResults(lastData);
}

function renderTable(rows) {
  document.getElementById('results').innerHTML = `<div class="table-wrap"><table>
    <thead><tr><th>ID</th><th>Name</th><th>Borough</th><th>Cuisine</th><th>Address</th><th>Grade</th><th>Score</th></tr></thead>
    <tbody>${rows.map(r => {
      const g = r.grades&&r.grades[0], letter=g?g.grade:'—', score=g?g.score:'—';
      const addr = r.address?`${r.address.building} ${r.address.street}`:'—';
      return `<tr onclick="openModal(${JSON.stringify(JSON.stringify(r))})">
        <td class="td-id">${r.restaurant_id}</td>
        <td class="td-name">${esc(r.name)}</td>
        <td><span class="tag tag-borough">${esc(r.borough)}</span></td>
        <td class="td-cuisine">${esc(r.cuisine)}</td>
        <td class="td-address" title="${esc(addr)}">${esc(addr)}</td>
        <td><span class="grade-letter grade-${letter}">${letter}</span></td>
        <td style="font-family:var(--mono);color:${scoreColor(score)}">${score}</td>
      </tr>`;
    }).join('')}</tbody></table></div>`;
}

function renderCards(rows) {
  document.getElementById('results').innerHTML = `<div class="card-grid">${rows.map(r => {
    const addr = r.address?`${r.address.building} ${r.address.street}, ${r.address.zipcode}`:'';
    const grades = (r.grades||[]).slice(0,4);
    return `<div class="card" onclick="openModal(${JSON.stringify(JSON.stringify(r))})">
      <div class="card-name">${esc(r.name)}</div>
      <div class="card-id">#${r.restaurant_id}</div>
      <div class="card-meta">
        <span class="tag tag-borough">${esc(r.borough)}</span>
        <span class="tag tag-cuisine">${esc(r.cuisine)}</span>
      </div>
      ${addr?`<div class="card-address">${esc(addr)}</div>`:''}
      <div class="grades-row">
        ${grades.map((g,i)=>`<div class="grade-chip${i===0?' grade-latest':''}">
          <span class="grade-letter grade-${g.grade}">${g.grade}</span>
          <span class="grade-score">${g.score}</span>
        </div>`).join('')}
        ${r.grades&&r.grades.length>4?`<span style="font-family:var(--mono);font-size:10px;color:var(--muted2)">+${r.grades.length-4} more</span>`:''}
      </div>
    </div>`;
  }).join('')}</div>`;
}

function renderPagination(d) {
  const pg = document.getElementById('pagination');
  if (d.totalPages<=1) { pg.style.display='none'; return; }
  pg.style.display='flex';
  const cur=d.page, total=d.totalPages;
  let pages=new Set([1,total,cur]);
  for(let i=cur-1;i<=cur+1;i++) if(i>0&&i<=total) pages.add(i);
  pages=[...pages].sort((a,b)=>a-b);
  let html=`<button class="page-btn" ${cur===1?'disabled':''} onclick="runQuery(${cur-1})">‹</button>`;
  let prev=null;
  for(const p of pages){
    if(prev&&p-prev>1) html+=`<span class="page-info">…</span>`;
    html+=`<button class="page-btn ${p===cur?'active':''}" onclick="runQuery(${p})">${p}</button>`;
    prev=p;
  }
  html+=`<button class="page-btn" ${cur===total?'disabled':''} onclick="runQuery(${cur+1})">›</button>`;
  html+=`<span class="page-info">${cur} / ${total}</span>`;
  pg.innerHTML=html;
}

function openModal(jsonStr) {
  const r = JSON.parse(jsonStr);
  document.getElementById('modal-title').textContent = r.name;
  document.getElementById('modal-id').textContent    = 'restaurant_id: '+r.restaurant_id;
  const addr  = r.address?`${r.address.building} ${r.address.street}, ${r.address.zipcode}`:'—';
  const coord = r.address&&r.address.coord?r.address.coord.join(', '):'';
  const gradesHtml = (r.grades||[]).length
    ? `<table class="grades-table"><thead><tr><th>#</th><th>Date</th><th>Grade</th><th>Score</th></tr></thead><tbody>
       ${r.grades.map((g,i)=>`<tr>
         <td style="color:var(--muted2)">${i===0?'<span style="color:var(--accent)">Latest</span>':i+1}</td>
         <td>${g.date||'—'}</td>
         <td><span class="grade-letter grade-${g.grade}" style="font-size:14px">${g.grade}</span></td>
         <td style="color:${scoreColor(g.score)};font-weight:600">${g.score}</td>
       </tr>`).join('')}</tbody></table>`
    : '<span style="color:var(--muted)">No grades.</span>';
  document.getElementById('modal-body').innerHTML = `
    <div class="card-meta" style="margin-bottom:16px">
      <span class="tag tag-borough">${esc(r.borough)}</span>
      <span class="tag tag-cuisine">${esc(r.cuisine)}</span>
    </div>
    <div class="modal-field">
      <div class="modal-field-label">Address</div>
      <div class="modal-field-val">${esc(addr)}</div>
      ${coord?`<div style="font-family:var(--mono);font-size:11px;color:var(--muted);margin-top:3px">📍 ${coord}</div>`:''}
    </div>
    <div class="modal-field">
      <div class="modal-field-label">Inspection Grades (newest first)</div>
      ${gradesHtml}
    </div>`;
  document.getElementById('modal-overlay').classList.add('open');
}

function closeModal() { document.getElementById('modal-overlay').classList.remove('open'); }
function showLoading() {
  document.getElementById('results').innerHTML=`<div class="state-center"><div class="spinner"></div><span>Querying…</span></div>`;
  document.getElementById('pagination').style.display='none';
}
function showError(msg) {
  document.getElementById('results').innerHTML=`<div class="state-center" style="color:var(--red)">
    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span>${esc(msg)}</span></div>`;
}
function esc(s){if(!s)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function scoreColor(s){if(s==='—'||s===undefined)return'var(--muted)';if(s<=13)return'var(--accent)';if(s<=27)return'var(--yellow)';return'var(--red)';}
function resetFilters(){
  document.getElementById('filter-borough').value='';
  document.getElementById('filter-cuisine').value='';
  document.getElementById('filter-score-num').value='';
  document.getElementById('filter-score-range').value=100;
  document.querySelectorAll('.op-btn').forEach(b=>b.classList.toggle('active',b.dataset.op==='lte'));
  currentOp='lte';
  runQuery(1);
}
</script>
</body>
</html>
