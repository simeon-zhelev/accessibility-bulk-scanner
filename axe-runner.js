#!/usr/bin/env node
/**
 * axe-runner.js — headless axe-core engine for the Accessibility Bulk Scanner
 * ---------------------------------------------------------------------------
 * Launches a single headless Chromium instance (via Playwright), then renders
 * each URL and runs the open-source axe-core engine (@axe-core/playwright)
 * against the fully-rendered DOM. Results are streamed back as NDJSON — one
 * compact JSON object per line on stdout — so the PHP orchestrator can show
 * live progress and aggregate as results arrive.
 *
 * This is the part the PHP layer cannot do itself: accessibility rules only
 * mean anything against a rendered page, which requires a real browser.
 *
 * Input:  a JSON file (--input) containing either
 *           ["https://a", "https://b", ...]
 *         or
 *           [{ "url": "https://a", "group": "Pages" }, ...]
 *         If --input is omitted, the same JSON is read from stdin.
 *
 * Output (stdout), one line per URL:
 *   {"type":"result","url":"...","ok":true,
 *    "engine":"4.x","violations":[...],"incomplete":[...],
 *    "counts":{"critical":0,"serious":1,"moderate":0,"minor":2,
 *              "violations":3,"incomplete":1,"passes":42}}
 *   {"type":"result","url":"...","ok":false,"error":"Timeout 30000ms exceeded"}
 *
 * Each violation/incomplete entry is trimmed to:
 *   { id, impact, help, description, helpUrl, tags, nodeCount, samples:[{target,html}] }
 *
 * Progress lines are written to stderr (so they don't pollute the NDJSON).
 *
 * Usage:
 *   node axe-runner.js --input urls.json --concurrency 4 \
 *        --tags wcag2a,wcag2aa,wcag21a,wcag21aa,best-practice \
 *        --timeout 30000
 */

'use strict';

const fs = require('fs');
const path = require('path');
const os = require('os');

// ─── Lazy, friendly dependency loading ──────────────────────────────────────
let chromium, AxeBuilder;
try {
  ({ chromium } = require('playwright'));
  AxeBuilder = require('@axe-core/playwright').default;
} catch (e) {
  process.stderr.write(
    '\n❌  Missing Node dependencies. Run:\n' +
    '      npm install\n' +
    '      npx playwright install chromium\n' +
    `   (original error: ${e.message})\n`
  );
  process.exit(2);
}

// ─── Arg parsing ─────────────────────────────────────────────────────────────
function parseArgs(argv) {
  const out = {
    input: null,
    concurrency: 4,
    tags: 'wcag2a,wcag2aa,wcag21a,wcag21aa,best-practice',
    timeout: 30000,
    waitUntil: 'load',          // load | domcontentloaded | networkidle
  };
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    const next = () => argv[++i];
    if (a === '--input') out.input = next();
    else if (a === '--concurrency') out.concurrency = Math.max(1, parseInt(next(), 10) || 4);
    else if (a === '--tags') out.tags = next();
    else if (a === '--timeout') out.timeout = Math.max(1000, parseInt(next(), 10) || 30000);
    else if (a === '--wait-until') out.waitUntil = next();
  }
  return out;
}

function readInput(inputPath) {
  let raw;
  if (inputPath) {
    raw = fs.readFileSync(inputPath, 'utf8');
  } else {
    raw = fs.readFileSync(0, 'utf8'); // stdin
  }
  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch (e) {
    process.stderr.write(`❌  Could not parse input JSON: ${e.message}\n`);
    process.exit(2);
  }
  if (!Array.isArray(parsed)) {
    process.stderr.write('❌  Input JSON must be an array of URLs or {url,group} objects.\n');
    process.exit(2);
  }
  return parsed.map((item) =>
    typeof item === 'string' ? { url: item } : { url: item.url, group: item.group }
  ).filter((it) => it && it.url);
}

// ─── Result trimming ─────────────────────────────────────────────────────────
const IMPACTS = ['critical', 'serious', 'moderate', 'minor'];

function trimEntry(entry) {
  const nodes = entry.nodes || [];
  const samples = nodes.slice(0, 3).map((n) => ({
    target: Array.isArray(n.target) ? n.target.join(' ') : String(n.target || ''),
    html: (n.html || '').slice(0, 240),
  }));
  return {
    id: entry.id,
    impact: entry.impact || null,
    help: entry.help || entry.id,
    description: entry.description || '',
    helpUrl: entry.helpUrl || '',
    tags: entry.tags || [],
    nodeCount: nodes.length,
    samples,
  };
}

function countByImpact(violations) {
  const c = { critical: 0, serious: 0, moderate: 0, minor: 0 };
  for (const v of violations) {
    // Count *element instances*, not just rules, to reflect true volume.
    const inc = v.nodeCount || 1;
    if (IMPACTS.includes(v.impact)) c[v.impact] += inc;
  }
  return c;
}

// ─── Scan one URL ────────────────────────────────────────────────────────────
async function scanUrl(browser, urlObj, opts) {
  const tags = opts.tags.split(',').map((t) => t.trim()).filter(Boolean);
  const context = await browser.newContext({
    userAgent: 'AccessibilityBulkScanner/1.0 (+axe-core)',
    bypassCSP: true,
  });
  const page = await context.newPage();
  try {
    await page.goto(urlObj.url, { waitUntil: opts.waitUntil, timeout: opts.timeout });

    let builder = new AxeBuilder({ page });
    if (tags.length) builder = builder.withTags(tags);
    const results = await builder.analyze();

    const violations = (results.violations || []).map(trimEntry);
    const incomplete = (results.incomplete || []).map(trimEntry);
    const counts = countByImpact(violations);
    counts.violations = violations.reduce((s, v) => s + (v.nodeCount || 1), 0);
    counts.incomplete = incomplete.reduce((s, v) => s + (v.nodeCount || 1), 0);
    counts.rules = violations.length;
    counts.passes = (results.passes || []).length;

    return {
      type: 'result',
      url: urlObj.url,
      group: urlObj.group || null,
      ok: true,
      engine: (results.testEngine && results.testEngine.version) || null,
      violations,
      incomplete,
      counts,
    };
  } catch (err) {
    return {
      type: 'result',
      url: urlObj.url,
      group: urlObj.group || null,
      ok: false,
      error: (err && err.message ? err.message : String(err)).split('\n')[0].slice(0, 200),
    };
  } finally {
    await context.close().catch(() => {});
  }
}

// ─── Concurrency pool ────────────────────────────────────────────────────────
async function runPool(browser, urls, opts) {
  let index = 0;
  let done = 0;
  const total = urls.length;

  const emit = (obj) => process.stdout.write(JSON.stringify(obj) + '\n');

  async function worker() {
    while (true) {
      const myIndex = index++;
      if (myIndex >= total) return;
      const urlObj = urls[myIndex];
      const res = await scanUrl(browser, urlObj, opts);
      done++;
      emit(res);
      const tag = res.ok
        ? `✓ ${res.counts.violations} issues (${res.counts.rules} rules)`
        : `✗ ${res.error}`;
      process.stderr.write(`  [${done}/${total}] ${tag}  ${urlObj.url}\n`);
    }
  }

  const workers = [];
  const n = Math.min(opts.concurrency, total || 1);
  for (let i = 0; i < n; i++) workers.push(worker());
  await Promise.all(workers);
}

// ─── Browser launch (with sandbox fallback) ─────────────────────────────────
/**
 * Launch headless Chromium. The standard launch works on macOS / Windows /
 * normal Linux. Some locked-down Linux containers crash Playwright's default
 * `headless_shell` binary (SIGSEGV); in that case we fall back to the full
 * Chromium build with the new headless mode. An explicit executable can also
 * be forced via the AXE_CHROME_PATH environment variable.
 */
async function launchBrowser() {
  const baseArgs = ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'];

  // 1 — explicit override
  if (process.env.AXE_CHROME_PATH) {
    return chromium.launch({
      headless: true,
      executablePath: process.env.AXE_CHROME_PATH,
      args: baseArgs.concat('--headless=new'),
    });
  }

  // 2 — standard launch (the normal path on real machines)
  try {
    return await chromium.launch({ headless: true, args: baseArgs });
  } catch (firstErr) {
    process.stderr.write(
      `   ⚠  Default Chromium launch failed (${firstErr.message.split('\n')[0]}); ` +
      'trying full-build fallback …\n'
    );
  }

  // 3 — fallback: locate a full chromium build in the Playwright cache
  const cacheRoot = path.join(os.homedir(), '.cache', 'ms-playwright');
  let exe = null;
  try {
    for (const dir of fs.readdirSync(cacheRoot)) {
      if (/^chromium-\d+$/.test(dir)) {
        for (const candidate of [
          path.join(cacheRoot, dir, 'chrome-linux', 'chrome'),
          path.join(cacheRoot, dir, 'chrome-mac', 'Chromium.app', 'Contents', 'MacOS', 'Chromium'),
          path.join(cacheRoot, dir, 'chrome-win', 'chrome.exe'),
        ]) {
          if (fs.existsSync(candidate)) { exe = candidate; break; }
        }
      }
      if (exe) break;
    }
  } catch (_) { /* cache dir not found */ }

  if (!exe) throw new Error('Chromium launch failed and no full build found. Run: npx playwright install chromium');
  return chromium.launch({
    headless: true,
    executablePath: exe,
    args: baseArgs.concat('--headless=new'),
  });
}

// ─── Main ────────────────────────────────────────────────────────────────────
(async () => {
  const opts = parseArgs(process.argv);
  const urls = readInput(opts.input);

  if (!urls.length) {
    process.stderr.write('❌  No URLs to scan.\n');
    process.exit(1);
  }

  process.stderr.write(
    `⚡  axe-core scan: ${urls.length} URLs, concurrency ${opts.concurrency}, tags [${opts.tags}]\n`
  );

  // Emit a small meta line first so the orchestrator can record engine config.
  process.stdout.write(JSON.stringify({ type: 'meta', total: urls.length, tags: opts.tags }) + '\n');

  const browser = await launchBrowser();

  try {
    await runPool(browser, urls, opts);
  } finally {
    await browser.close().catch(() => {});
  }
})().catch((e) => {
  process.stderr.write(`❌  Fatal: ${e && e.stack ? e.stack : e}\n`);
  process.exit(1);
});
