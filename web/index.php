<?php /* Accessibility Bulk Scanner — web frontend. Run with: php -S 127.0.0.1:8081 -t web */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accessibility Bulk Scanner</title>
<style>
  :root {
    --bg:#f8fafc; --panel:#ffffff; --ink:#1e293b; --muted:#64748b;
    --line:#e2e8f0; --accent:#2563eb; --accent-ink:#fff;
    --critical:#ef4444; --serious:#f97316; --moderate:#eab308; --minor:#3b82f6;
    --ok:#10b981;
    --radius:12px; --shadow:none;
  }
  * { box-sizing:border-box; }
  body {
    margin:0; font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    color:var(--ink);
    background:var(--bg);
    min-height:100vh;
  }
  .wrap { max-width:980px; margin:0 auto; padding:48px 20px 80px; }
  header.hero { text-align:center; color:var(--ink); margin-bottom:32px; }
  header.hero h1 { font-size:34px; margin:0 0 10px; letter-spacing:-.02em; color:#0f172a; }
  header.hero p { margin:0 auto; max-width:600px; color:#475569; }
  header.hero .eyebrow {
    display:inline-block; font-size:12px; letter-spacing:.14em; text-transform:uppercase;
    color:#2563eb; background:#eff6ff; border:1px solid #dbeafe; padding:5px 12px; border-radius:999px; margin-bottom:16px;
  }
  .card { background:var(--panel); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); padding:28px; }
  form .grid { display:grid; grid-template-columns:1fr 1fr; gap:18px 20px; }
  .field { display:flex; flex-direction:column; gap:6px; }
  .field.full { grid-column:1 / -1; }
  label { font-weight:600; font-size:14px; }
  label .hint { font-weight:400; color:var(--muted); font-size:13px; }
  input[type=text], input[type=url], input[type=number], select {
    font:inherit; padding:11px 13px; border:1px solid var(--line); border-radius:10px; background:#fff; color:var(--ink);
    transition:border-color .15s, box-shadow .15s;
  }
  input[type=text], input[type=url], input[type=number], select { border:1px solid #cbd5e1; }
  input:focus, select:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(37,99,235,.15); }
  .check { flex-direction:row; align-items:center; gap:10px; }
  .check input { width:18px; height:18px; accent-color:var(--accent); }
  .actions { margin-top:24px; display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
  button.primary {
    font:inherit; font-weight:600; background:var(--accent); color:var(--accent-ink);
    border:0; padding:13px 26px; border-radius:10px; cursor:pointer; transition:transform .05s, background .15s;
  }
  button.primary:hover { background:#1d4ed8; }
  button.primary:active { transform:translateY(1px); }
  button.primary:disabled { opacity:.55; cursor:not-allowed; }
  .note { color:var(--muted); font-size:13px; }

  /* progress + results */
  #run { display:none; margin-top:26px; }
  #run.active { display:block; }
  .statusbar { display:flex; align-items:center; gap:12px; margin-bottom:14px; }
  .spinner {
    width:18px; height:18px; border:2px solid var(--line); border-top-color:var(--accent);
    border-radius:50%; animation:spin .8s linear infinite; flex:none;
  }
  .spinner.hidden { display:none; }
  @keyframes spin { to { transform:rotate(360deg); } }
  #statusMsg { font-weight:600; }
  .bar { height:10px; background:var(--line); border-radius:999px; overflow:hidden; }
  .bar > i { display:block; height:100%; width:0; background:linear-gradient(90deg,#3b82f6,#2563eb); transition:width .25s; }
  .counter { font-size:13px; color:var(--muted); margin-top:8px; }
  .log {
    margin-top:16px; max-height:230px; overflow:auto; border:1px solid var(--line); border-radius:10px;
    font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; background:#f8fafc;
  }
  .log .row { display:flex; gap:10px; padding:6px 12px; border-bottom:1px solid #eef2f7; align-items:center; }
  .log .row:last-child { border-bottom:0; }
  .log .badge { flex:none; width:9px; height:9px; border-radius:50%; }
  .log .url { color:#334155; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1; }
  .log .num { flex:none; color:var(--muted); }

  .summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:12px; margin:6px 0 18px; }
  .stat { background:#f8fafc; border:1px solid var(--line); border-radius:12px; padding:14px; text-align:center; }
  .stat .n { font-size:26px; font-weight:700; line-height:1.1; }
  .stat .l { font-size:12px; color:var(--muted); margin-top:4px; }
  .stat.critical .n { color:var(--critical); } .stat.serious .n { color:var(--serious); }
  .stat.moderate .n { color:var(--moderate); } .stat.minor .n { color:var(--minor); }
  .stat.ok .n { color:var(--ok); }
  .resultActions { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
  a.btn {
    text-decoration:none; font-weight:600; font-size:14px; padding:10px 18px; border-radius:10px;
    border:1px solid var(--line); color:var(--ink); background:#fff;
  }
  a.btn.solid { background:var(--accent); color:#fff; border-color:var(--accent); }
  iframe.report { width:100%; height:680px; border:1px solid var(--line); border-radius:12px; background:#fff; }
  .errbox { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:14px 16px; border-radius:10px; white-space:pre-wrap; }
  footer { text-align:center; color:#64748b; font-size:13px; margin-top:34px; }
  footer a { color:#2563eb; }
</style>
</head>
<body>
<div class="wrap">
  <header class="hero">
    <span class="eyebrow">axe-core · WCAG</span>
    <h1>Accessibility Bulk Scanner</h1>
    <p>Audit every page of a website against WCAG, driven by its XML sitemap. Enter a website address — the sitemap is found automatically — or paste a sitemap URL directly, then watch the results appear live.</p>
  </header>

  <div class="card">
    <form id="form">
      <div class="grid">
        <div class="field full">
          <label for="sitemap">Website or sitemap URL <span class="hint">— enter a site address to auto-find its sitemap, or paste a sitemap URL</span></label>
          <input type="text" id="sitemap" name="sitemap" required
                 placeholder="example.com  —  or  https://example.com/sitemap_index.xml" autocomplete="off" spellcheck="false">
        </div>

        <div class="field">
          <label for="standard">WCAG standard</label>
          <select id="standard" name="standard">
            <option value="wcag21aa" selected>WCAG 2.1 AA (recommended)</option>
            <option value="wcag2a">WCAG 2.0 A</option>
            <option value="wcag2aa">WCAG 2.0 AA</option>
            <option value="wcag21a">WCAG 2.1 A</option>
            <option value="wcag22aa">WCAG 2.2 AA</option>
            <option value="section508">Section 508</option>
          </select>
        </div>

        <div class="field">
          <label for="max-urls">Max pages <span class="hint">— blank = all</span></label>
          <input type="number" id="max-urls" name="max-urls" min="1" max="5000" placeholder="all (e.g. 20 for a trial)">
        </div>

        <div class="field">
          <label for="concurrency">Concurrency <span class="hint">— parallel pages</span></label>
          <input type="number" id="concurrency" name="concurrency" min="1" max="12" value="4">
        </div>

        <div class="field">
          <label for="timeout">Per-page timeout <span class="hint">— milliseconds</span></label>
          <input type="number" id="timeout" name="timeout" min="1000" max="120000" step="1000" value="30000">
        </div>

        <div class="field check full">
          <input type="checkbox" id="no-best-practice" name="no-best-practice">
          <label for="no-best-practice" style="font-weight:400">Test formal WCAG rules only (drop axe <code>best-practice</code> tag)</label>
        </div>
      </div>

      <div class="actions">
        <button type="submit" class="primary" id="submitBtn">Scan website</button>
        <span class="note">Each page is rendered in a real browser — budget ~1–4&nbsp;s per page.</span>
      </div>
    </form>

    <section id="run">
      <div class="statusbar">
        <div class="spinner" id="spinner"></div>
        <span id="statusMsg">Starting…</span>
      </div>
      <div class="bar"><i id="barFill"></i></div>
      <div class="counter" id="counter"></div>
      <div class="log" id="log" hidden></div>

      <div id="result" hidden>
        <div class="summary" id="summary"></div>
        <div class="resultActions" id="resultActions"></div>
        <iframe class="report" id="reportFrame" title="Accessibility report"></iframe>
      </div>

      <div class="errbox" id="error" hidden></div>
    </section>
  </div>

  <footer>
    Powered by <a href="https://github.com/dequelabs/axe-core" target="_blank" rel="noopener">axe-core</a>.
    No Deque license or API key required.
  </footer>
</div>

<script>
const form = document.getElementById('form');
const runEl = document.getElementById('run');
const submitBtn = document.getElementById('submitBtn');
const spinner = document.getElementById('spinner');
const statusMsg = document.getElementById('statusMsg');
const barFill = document.getElementById('barFill');
const counter = document.getElementById('counter');
const logEl = document.getElementById('log');
const resultEl = document.getElementById('result');
const summaryEl = document.getElementById('summary');
const resultActions = document.getElementById('resultActions');
const reportFrame = document.getElementById('reportFrame');
const errorEl = document.getElementById('error');

const impactColor = { critical:'#ef4444', serious:'#f97316', moderate:'#eab308', minor:'#3b82f6' };
let es = null;

form.addEventListener('submit', (e) => {
  e.preventDefault();
  if (es) es.close();

  // reset UI
  runEl.classList.add('active');
  spinner.classList.remove('hidden');
  statusMsg.textContent = 'Starting…';
  barFill.style.width = '0';
  counter.textContent = '';
  logEl.innerHTML = ''; logEl.hidden = true;
  resultEl.hidden = true;
  errorEl.hidden = true;
  submitBtn.disabled = true;
  reportFrame.removeAttribute('src');

  // build query string
  const data = new FormData(form);
  const params = new URLSearchParams();
  for (const [k, v] of data.entries()) {
    if (k === 'no-best-practice') { params.set(k, '1'); continue; }
    if (v !== '') params.set(k, v);
  }

  es = new EventSource('scan.php?' + params.toString());

  es.addEventListener('status', (ev) => {
    statusMsg.textContent = JSON.parse(ev.data).message;
  });

  es.addEventListener('meta', (ev) => {
    const d = JSON.parse(ev.data);
    statusMsg.textContent = `Scanning ${d.total} page${d.total === 1 ? '' : 's'} (concurrency ${d.concurrency})…`;
    counter.textContent = `0 / ${d.total} · tags: ${d.tags}`;
    logEl.hidden = false;
  });

  es.addEventListener('page', (ev) => {
    const d = JSON.parse(ev.data);
    barFill.style.width = (100 * d.done / d.total).toFixed(1) + '%';
    counter.textContent = `${d.done} / ${d.total} pages scanned`;
    addLogRow(d);
  });

  es.addEventListener('done', (ev) => {
    const d = JSON.parse(ev.data);
    finish();
    statusMsg.textContent = 'Scan complete.';
    renderSummary(d.summary);
    renderActions(d);
    reportFrame.src = d.reportUrl;
    resultEl.hidden = false;
  });

  es.addEventListener('error', (ev) => {
    // SSE network errors have no data; our server-sent errors do.
    if (ev.data) {
      try { showError(JSON.parse(ev.data).message); return; } catch (_) {}
    }
    if (es.readyState === EventSource.CLOSED) {
      showError('The connection to the scanner was lost. Check the server console for details.');
    }
  });
});

function addLogRow(d) {
  const row = document.createElement('div');
  row.className = 'row';
  const badge = document.createElement('span');
  badge.className = 'badge';
  let label;
  if (!d.ok) {
    badge.style.background = '#94a3b8';
    label = '✗ ' + (d.error || 'error');
  } else {
    const c = d.counts || {};
    const v = c.violations || 0;
    badge.style.background = v === 0 ? '#10b981'
      : c.critical ? impactColor.critical
      : c.serious ? impactColor.serious
      : c.moderate ? impactColor.moderate : impactColor.minor;
    label = v === 0 ? 'clean' : `${v} issue${v === 1 ? '' : 's'}`;
  }
  row.innerHTML = `<span class="num">[${d.done}]</span>`;
  row.appendChild(badge);
  const url = document.createElement('span');
  url.className = 'url'; url.textContent = d.url; url.title = d.url;
  row.appendChild(url);
  const lab = document.createElement('span');
  lab.className = 'num'; lab.textContent = label;
  row.appendChild(lab);
  logEl.appendChild(row);
  logEl.scrollTop = logEl.scrollHeight;
}

function renderSummary(s) {
  const cards = [
    { n:s.pages, l:'Pages scanned' },
    { n:s.pagesWithIssues, l:'Pages with issues' },
    { n:s.cleanPages, l:'Clean pages', cls:'ok' },
    { n:s.uniqueRules, l:'Failing rules' },
    { n:s.critical, l:'Critical', cls:'critical' },
    { n:s.serious, l:'Serious', cls:'serious' },
    { n:s.moderate, l:'Moderate', cls:'moderate' },
    { n:s.minor, l:'Minor', cls:'minor' },
  ];
  if (s.errorPages) cards.push({ n:s.errorPages, l:'Pages errored' });
  summaryEl.innerHTML = cards.map(c =>
    `<div class="stat ${c.cls || ''}"><div class="n">${c.n}</div><div class="l">${c.l}</div></div>`
  ).join('');
}

function renderActions(d) {
  let html =
    `<a class="btn solid" href="${d.reportUrl}" target="_blank" rel="noopener">Open full report ↗</a>`;
  if (d.pdfUrl) html += `<a class="btn" href="${d.pdfUrl}" download>Download PDF</a>`;
  html += `<a class="btn" href="${d.csvUrl}" download>Download CSV</a>`;
  resultActions.innerHTML = html;
}

function showError(msg) {
  finish();
  statusMsg.textContent = 'Scan failed.';
  errorEl.textContent = msg;
  errorEl.hidden = false;
}

function finish() {
  spinner.classList.add('hidden');
  submitBtn.disabled = false;
  if (es) es.close();
}
</script>
</body>
</html>
