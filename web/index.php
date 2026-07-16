<?php /* Accessibility Bulk Scanner — web frontend. Run with: php -S 127.0.0.1:8081 -t web */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accessibility Bulk Scanner</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Source+Sans+3:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --ink:#0F1E33; --body:#33415C; --muted:#64748B; --soft:#94A3B8;
    --line:#E6EAF1; --line-strong:#C9D4E5; --panel:#ffffff; --bg:#EEF1F5;
    --accent:#0D8A7E; --accent-ink:#fff; --accent-hover:#0B7468;
    --accent-tint:#E6F4F2; --accent-line:#BFE3DE;
    --critical:#CF4A3A; --serious:#D97A2B; --moderate:#D99A2B; --minor:#2A78D6;
    --ok:#0D8A7E;
    --radius:20px; --radius-sm:12px;
    --shadow:0 1px 2px rgba(15,30,51,.04), 0 12px 30px rgba(15,30,51,.05);
  }
  * { box-sizing:border-box; }
  body {
    margin:0; font-family:'Source Sans 3', system-ui, -apple-system, Helvetica, Arial, sans-serif;
    font-size:16px; line-height:1.55; color:var(--body); background:var(--bg); min-height:100vh;
    -webkit-font-smoothing:antialiased;
  }
  .wrap { max-width:820px; margin:0 auto; padding:56px 20px 80px; }

  header.hero { text-align:center; margin-bottom:32px; }
  header.hero::before {
    content:"✦"; display:flex; align-items:center; justify-content:center;
    width:64px; height:64px; margin:0 auto 22px; font-size:28px; color:var(--accent);
    background:linear-gradient(160deg,#EAF6F4,#D8EEEA); border-radius:20px;
    box-shadow:0 8px 20px rgba(13,138,126,.18);
  }
  header.hero h1 {
    font-family:'Poppins', sans-serif; font-weight:700; font-size:clamp(30px, 6.5vw, 44px);
    margin:0 0 14px; letter-spacing:-.02em; color:var(--ink); line-height:1.05;
  }
  header.hero p { margin:0 auto; max-width:560px; font-size:clamp(16px, 3.6vw, 18px); color:var(--muted); }
  header.hero .eyebrow {
    display:inline-block; font-family:'Poppins', sans-serif; font-size:12px; font-weight:600;
    letter-spacing:.14em; text-transform:uppercase; color:var(--accent);
    background:var(--accent-tint); border:1px solid var(--accent-line);
    padding:6px 14px; border-radius:999px; margin-bottom:18px;
  }

  .card { background:var(--panel); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); padding:30px; }
  form .grid { display:grid; grid-template-columns:1fr 1fr; gap:20px 22px; }
  .field { display:flex; flex-direction:column; gap:7px; }
  .field.full { grid-column:1 / -1; }
  label { font-family:'Poppins', sans-serif; font-weight:600; font-size:14px; color:var(--ink); }
  label .hint { font-family:'Source Sans 3', sans-serif; font-weight:400; color:var(--muted); font-size:13px; }
  input[type=text], input[type=url], input[type=number], select {
    font:inherit; padding:13px 15px; border:1px solid var(--line-strong); border-radius:var(--radius-sm);
    background:#fff; color:var(--ink); transition:border-color .15s, box-shadow .15s;
  }
  input::placeholder { color:var(--soft); }
  #sitemap { padding:16px 18px; border-radius:14px; font-size:16px; }
  input:focus, select:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(13,138,126,.15); }

  /* custom dropdown — replace the native arrow with a chevron and give it room */
  select {
    appearance:none; -webkit-appearance:none; -moz-appearance:none;
    padding-right:46px; cursor:pointer;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2364748B' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 16px center; background-size:14px;
  }
  select::-ms-expand { display:none; }
  select:hover { border-color:var(--accent-line); }
  select:focus {
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%230D8A7E' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
  }
  select option { color:var(--ink); background:#fff; padding:10px; }
  select option:checked { color:var(--accent); font-weight:600; }

  .check {
    flex-direction:row; align-items:center; gap:12px;
    border:1px solid var(--line); border-radius:var(--radius-sm); padding:14px 16px; background:#fff;
    transition:border-color .15s, background .15s;
  }
  .check:hover { border-color:var(--accent-line); background:var(--accent-tint); }
  .check input { width:18px; height:18px; accent-color:var(--accent); flex:none; }
  .check label { font-family:'Source Sans 3', sans-serif; font-weight:400; color:var(--body); }
  .check code {
    font-family:'IBM Plex Mono', ui-monospace, monospace; font-size:.85em;
    background:var(--bg); border:1px solid var(--line); padding:1px 5px; border-radius:5px;
  }

  .actions { margin-top:26px; display:flex; gap:14px; align-items:center; flex-wrap:wrap; }
  button.primary {
    font-family:'Poppins', sans-serif; font-weight:700; font-size:16px;
    background:var(--accent); color:var(--accent-ink);
    border:0; padding:15px 30px; border-radius:999px; cursor:pointer;
    transition:transform .05s, background .15s, box-shadow .15s;
    box-shadow:0 8px 18px rgba(13,138,126,.22);
  }
  button.primary:hover { background:var(--accent-hover); }
  button.primary:active { transform:translateY(1px); }
  button.primary:disabled { opacity:.55; cursor:not-allowed; box-shadow:none; }
  .note { color:var(--muted); font-size:13px; }

  /* collapsed form — shown while a scan runs and after it finishes */
  .formSummary { display:none; align-items:center; gap:12px; }
  form.minimized .grid, form.minimized .actions { display:none; }
  form.minimized .formSummary { display:flex; }
  .formSummary .label { flex:none; font-family:'Poppins', sans-serif; font-weight:600; font-size:14px; color:var(--ink); }
  .formSummary .target {
    flex:1; min-width:0; color:var(--body);
    font-family:'IBM Plex Mono', ui-monospace, monospace; font-size:13px;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  }
  button.editBtn {
    margin-left:auto; flex:none; cursor:pointer;
    font-family:'Poppins', sans-serif; font-weight:600; font-size:13px;
    background:#fff; color:var(--accent); border:1px solid var(--accent-line);
    padding:9px 18px; border-radius:999px; transition:background .15s, border-color .15s;
  }
  button.editBtn:hover { background:var(--accent-tint); border-color:var(--accent); }
  @media (max-width:640px) {
    .formSummary { flex-wrap:wrap; }
    .formSummary .target { flex-basis:100%; order:3; }
  }

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
  #statusMsg { font-family:'Poppins', sans-serif; font-weight:600; color:var(--ink); }
  button.stop {
    margin-left:auto; font-family:'Poppins', sans-serif; font-weight:600; font-size:14px; cursor:pointer;
    background:#fff; color:var(--critical); border:1px solid #E7C3BC;
    padding:9px 18px; border-radius:999px; transition:background .15s, border-color .15s;
  }
  button.stop:hover { background:#FBEEEB; border-color:#DCA99F; }
  button.stop:disabled { opacity:.55; cursor:not-allowed; }
  .bar { height:10px; background:var(--line); border-radius:999px; overflow:hidden; }
  .bar > i { display:block; height:100%; width:0; background:linear-gradient(90deg,#22B3A2,var(--accent)); transition:width .25s; }
  .counter { font-size:13px; color:var(--muted); margin-top:8px; }
  .log {
    margin-top:16px; max-height:230px; overflow:auto; border:1px solid var(--line); border-radius:var(--radius-sm);
    font:13px/1.5 'IBM Plex Mono', ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; background:var(--bg);
  }
  .log .row { display:flex; gap:10px; padding:7px 14px; border-bottom:1px solid var(--line); align-items:center; }
  .log .row:last-child { border-bottom:0; }
  .log .badge { flex:none; width:9px; height:9px; border-radius:50%; }
  .log .url { color:var(--body); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1; }
  .log .num { flex:none; color:var(--soft); }

  .summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:12px; margin:6px 0 18px; }
  .stat { background:var(--bg); border:1px solid var(--line); border-radius:var(--radius-sm); padding:16px; text-align:center; }
  .stat .n { font-family:'Poppins', sans-serif; font-size:28px; font-weight:700; line-height:1.1; color:var(--ink); }
  .stat .l { font-size:12px; color:var(--muted); margin-top:4px; }
  .stat.critical .n { color:var(--critical); } .stat.serious .n { color:var(--serious); }
  .stat.moderate .n { color:var(--moderate); } .stat.minor .n { color:var(--minor); }
  .stat.ok .n { color:var(--ok); }
  .resultActions { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
  a.btn {
    text-decoration:none; font-family:'Poppins', sans-serif; font-weight:600; font-size:14px;
    padding:11px 20px; border-radius:999px; border:1px solid var(--line-strong); color:var(--ink); background:#fff;
    transition:border-color .15s, background .15s;
  }
  a.btn:hover { border-color:var(--accent-line); background:var(--accent-tint); }
  a.btn.solid { background:var(--accent); color:#fff; border-color:var(--accent); box-shadow:0 8px 18px rgba(13,138,126,.22); }
  a.btn.solid:hover { background:var(--accent-hover); }
  iframe.report { width:100%; height:680px; border:1px solid var(--line); border-radius:var(--radius-sm); background:#fff; }
  .errbox { background:#FBEEEB; border:1px solid #E7C3BC; color:#8A2E20; padding:14px 16px; border-radius:var(--radius-sm); white-space:pre-wrap; }
  footer { text-align:center; color:var(--muted); font-size:13px; margin-top:34px; }
  footer a { color:var(--accent); }

  /* ── Mobile ──────────────────────────────────────────────────────────────── */
  @media (max-width:640px) {
    .wrap { padding:36px 14px 56px; }
    header.hero { margin-bottom:24px; }
    header.hero::before { width:56px; height:56px; margin-bottom:18px; font-size:26px; }
    header.hero .eyebrow { margin-bottom:14px; }

    .card { padding:20px; border-radius:16px; }
    form .grid { grid-template-columns:1fr; gap:16px; }   /* single column */
    #sitemap { padding:14px 15px; }

    /* full-width primary action for an easy tap target */
    .actions { margin-top:20px; flex-direction:column; align-items:stretch; gap:12px; }
    .actions .note { text-align:center; }
    button.primary { width:100%; padding:15px 20px; }

    /* keep the Stop button on its own line, right-aligned */
    .statusbar { flex-wrap:wrap; }
    button.stop { margin-left:auto; }

    .summary { grid-template-columns:1fr 1fr; }           /* two stats per row */

    /* stacked, full-width download/report buttons */
    .resultActions { flex-direction:column; }
    .resultActions a.btn { text-align:center; }

    iframe.report { height:70vh; min-height:420px; }
  }

  @media (max-width:340px) {
    .summary { grid-template-columns:1fr; }               /* one stat per row */
  }
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
      <div class="formSummary" id="formSummary">
        <span class="label" id="scanLabel">Scanning</span>
        <span class="target" id="scanTarget"></span>
        <button type="button" class="editBtn" id="editBtn">Edit &amp; rescan</button>
      </div>

      <div class="grid">
        <div class="field full">
          <label for="sitemap">Website or sitemap URL <span class="hint">— enter a site address to auto-find its sitemap, or paste a sitemap URL</span></label>
          <input type="text" id="sitemap" name="sitemap" required
                 placeholder="example.com  —  or  https://example.com/sitemap_index.xml" autocomplete="off" spellcheck="false">
        </div>

        <div class="field">
          <label for="standard">WCAG standard</label>
          <select id="standard" name="standard">
            <option value="wcag22aa" selected>WCAG 2.2 AA (recommended)</option>
            <option value="wcag21aa">WCAG 2.1 AA</option>
            <option value="wcag2a">WCAG 2.0 A</option>
            <option value="wcag2aa">WCAG 2.0 AA</option>
            <option value="wcag21a">WCAG 2.1 A</option>
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
        <button type="button" class="stop" id="stopBtn" hidden>Stop scan</button>
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
const editBtn = document.getElementById('editBtn');
const scanLabel = document.getElementById('scanLabel');
const scanTarget = document.getElementById('scanTarget');
const spinner = document.getElementById('spinner');
const statusMsg = document.getElementById('statusMsg');
const stopBtn = document.getElementById('stopBtn');
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
let scanToken = null;
let stopping = false;

form.addEventListener('submit', (e) => {
  e.preventDefault();
  if (es) es.close();

  // collapse the form so the live progress takes over the card
  scanLabel.textContent = 'Scanning';
  scanTarget.textContent = document.getElementById('sitemap').value.trim();
  form.classList.add('minimized');

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

  // per-scan token so the Stop button can signal this run on the server
  scanToken = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(36).slice(2))
                .replace(/[^A-Za-z0-9-]/g, '');
  stopping = false;
  stopBtn.hidden = false;
  stopBtn.disabled = false;
  stopBtn.textContent = 'Stop scan';

  // build query string
  const data = new FormData(form);
  const params = new URLSearchParams();
  for (const [k, v] of data.entries()) {
    if (k === 'no-best-practice') { params.set(k, '1'); continue; }
    if (v !== '') params.set(k, v);
  }
  params.set('token', scanToken);

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
    scanLabel.textContent = d.stopped ? 'Stopped' : 'Scanned';
    statusMsg.textContent = d.stopped
      ? `Scan stopped — report covers the ${d.summary.pages} page${d.summary.pages === 1 ? '' : 's'} scanned so far.`
      : 'Scan complete.';
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
    if (stopping) return; // we closed the stream on purpose; polling takes over
    if (es.readyState === EventSource.CLOSED) {
      showError('The connection to the scanner was lost. Check the server console for details.');
    }
  });
});

// Expand the form again so settings can be changed / a new scan started.
editBtn.addEventListener('click', () => {
  form.classList.remove('minimized');
  document.getElementById('sitemap').focus();
});

stopBtn.addEventListener('click', () => {
  if (!scanToken || stopping) return;
  stopping = true;
  stopBtn.disabled = true;
  stopBtn.textContent = 'Stopping…';
  statusMsg.textContent = 'Stopping — finishing pages already in flight…';

  // Closing the stream is the stop signal: the server sees the dropped
  // connection (connection_aborted) and terminates the browser engine. It then
  // builds a report for the pages already scanned and writes a status sidecar,
  // which we poll for here since the SSE `done` can no longer reach us.
  const token = scanToken;
  if (es) es.close();
  pollForStopped(token);
});

function pollForStopped(token) {
  const url = 'reports/' + encodeURIComponent(token) + '.status.json';
  const deadline = Date.now() + 120000; // give up after 2 min
  const tick = () => {
    if (!stopping || token !== scanToken) return; // superseded / already handled
    fetch(url, { cache: 'no-store' })
      .then(r => r.ok ? r.json() : Promise.reject())
      .then(d => {
        if (!stopping || token !== scanToken) return;
        finish();
        statusMsg.textContent =
          `Scan stopped — report covers the ${d.summary.pages} page${d.summary.pages === 1 ? '' : 's'} scanned so far.`;
        renderSummary(d.summary);
        renderActions(d);
        reportFrame.src = d.reportUrl;
        resultEl.hidden = false;
      })
      .catch(() => {
        if (Date.now() > deadline) {
          if (stopping) { finish(); statusMsg.textContent = 'Scan stopped.'; }
          return;
        }
        setTimeout(tick, 1200);
      });
  };
  tick();
}

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
  scanLabel.textContent = 'Scan failed';
  statusMsg.textContent = 'Scan failed.';
  errorEl.textContent = msg;
  errorEl.hidden = false;
}

function finish() {
  spinner.classList.add('hidden');
  submitBtn.disabled = false;
  stopBtn.hidden = true;
  stopping = false;
  scanToken = null;
  if (es) es.close();
}
</script>
</body>
</html>
