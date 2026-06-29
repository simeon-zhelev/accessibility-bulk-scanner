#!/usr/bin/env node
/**
 * html-to-pdf.js — render a finished HTML report to PDF
 * -----------------------------------------------------------------------------
 * The PHP layer already produces a self-contained, light-themed HTML report.
 * This helper opens that file in the same headless Chromium that powers the
 * scan and prints it to PDF — so there's no extra dependency and the PDF is a
 * pixel-faithful copy of the on-screen report.
 *
 * Before printing it expands every collapsible "Results" row so the PDF is a
 * complete document (nothing hidden behind a click).
 *
 * Usage:  node html-to-pdf.js <input.html> <output.pdf>
 */
const { chromium } = require('playwright');
const fs = require('fs');
const os = require('os');
const path = require('path');

// Mirror axe-runner.js's resilient launch (sandbox / full-build fallbacks).
async function launchBrowser() {
  const baseArgs = ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'];

  if (process.env.AXE_CHROME_PATH) {
    return chromium.launch({
      headless: true,
      executablePath: process.env.AXE_CHROME_PATH,
      args: baseArgs.concat('--headless=new'),
    });
  }

  try {
    return await chromium.launch({ headless: true, args: baseArgs });
  } catch (firstErr) {
    process.stderr.write(
      `   ⚠  Default Chromium launch failed (${firstErr.message.split('\n')[0]}); ` +
      'trying full-build fallback …\n'
    );
  }

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

async function main() {
  const input = process.argv[2];
  const output = process.argv[3];
  if (!input || !output) {
    process.stderr.write('usage: node html-to-pdf.js <input.html> <output.pdf>\n');
    process.exit(2);
  }
  if (!fs.existsSync(input)) {
    process.stderr.write(`❌  Input HTML not found: ${input}\n`);
    process.exit(2);
  }

  const browser = await launchBrowser();
  try {
    const page = await browser.newPage();
    await page.goto('file://' + path.resolve(input), { waitUntil: 'load' });

    // Expand every collapsible Results row so nothing is hidden in print.
    await page.evaluate(() => {
      document.querySelectorAll('tr.detail-row[hidden]').forEach(r => r.removeAttribute('hidden'));
      document.querySelectorAll('tr.expandable').forEach(r => r.classList.add('open'));
    });

    await page.pdf({
      path: output,
      format: 'A4',
      printBackground: true,
      margin: { top: '12mm', bottom: '16mm', left: '10mm', right: '10mm' },
      displayHeaderFooter: true,
      headerTemplate: '<span></span>',
      footerTemplate:
        '<div style="font-size:8px;color:#64748b;width:100%;padding:0 10mm;' +
        'display:flex;justify-content:space-between;">' +
        '<span>♿ Accessibility Bulk Report</span>' +
        '<span>Page <span class="pageNumber"></span> / <span class="totalPages"></span></span>' +
        '</div>',
    });
  } finally {
    await browser.close();
  }
}

main().catch((e) => {
  process.stderr.write('PDF generation failed: ' + (e && e.stack ? e.stack : e) + '\n');
  process.exit(1);
});
