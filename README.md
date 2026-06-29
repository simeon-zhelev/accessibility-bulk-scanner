# Accessibility Bulk Scanner

Audit the accessibility of **every page of a website** against WCAG, driven by its XML sitemap, in parallel — and get a self-contained, **light-themed** HTML dashboard + CSV export.

Works with **WordPress (Yoast SEO)**, **Shopify**, and any site exposing a standard XML sitemap or sitemap index. Just give it a website address — it **finds the sitemap automatically** (via `robots.txt` and the usual locations) — or point it straight at a sitemap URL.

## How it works

Accessibility rules only mean anything against a **rendered** page, so this tool is split in two:

- **`accessibility_scanner.php`** — the orchestrator. Crawls the sitemap, drives the scan, aggregates results, and writes the HTML / CSV / console reports. This is the part you run.
- **`axe-runner.js`** — a small headless-browser engine. Launches Chromium (via Playwright) once and runs the open-source **[axe-core](https://github.com/dequelabs/axe-core)** engine against each page, streaming results back to PHP as NDJSON.

axe-core is the same engine inside **Deque axe DevTools**, so the rule set and results match what you'd see there. **No Deque license or API key is required.** If you later license a commercial runner, you can swap it in with `--runner=PATH` as long as it emits the same NDJSON.

## Quick start

```bash
# 1. Install the browser engine (one time)
npm install
npx playwright install chromium

# 2a. Easiest — just give it the site URL; the sitemap is auto-discovered
php accessibility_scanner.php --url=https://example.com --standard=wcag21aa

# 2b. Or point straight at a sitemap (WordPress / Yoast)
php accessibility_scanner.php \
  --sitemap=https://example.com/sitemap_index.xml \
  --standard=wcag21aa \
  --concurrency=4 \
  --output=report.html \
  --csv=report.csv

# Shopify store
php accessibility_scanner.php \
  --sitemap=https://your-store.com/sitemap.xml \
  --standard=wcag21aa
```

> **Auto-discovery:** `--sitemap` and `--url` both accept either a plain site
> address (e.g. `https://example.com`) or a direct sitemap URL. Given a site
> address, the scanner reads `robots.txt` for `Sitemap:` directives and then
> probes common paths (`/sitemap_index.xml`, `/sitemap.xml`, `/wp-sitemap.xml`, …)
> until it finds a valid sitemap. If discovery fails, pass the sitemap URL directly.

**Tip:** for a first run on a large site, do a trial with `--max-urls=20` to confirm everything works before scanning all pages.

## Web UI

Prefer a browser to the command line? A small, light-themed landing page is included in `web/`. Enter a **website address** (the sitemap is found automatically) or a sitemap URL, pick your options, and it streams **live per-page progress** and shows the full report inline (plus HTML/CSV downloads) — no command line needed.

```bash
# Install once (same as above)
npm install
npx playwright install chromium

# Start the built-in PHP web server, then open http://127.0.0.1:8000
php -S 127.0.0.1:8000 -t web
```

It reuses the exact same engine as the CLI — `accessibility_scanner.php` + `axe-runner.js` — so results are identical. Generated reports are written to `web/reports/` (git-ignored).

> **Note:** the web UI runs scans on demand and renders arbitrary pages in a headless browser, so keep it bound to `127.0.0.1` / a trusted network rather than exposing it publicly.

## Requirements

- **PHP 7.4+** with the `curl` and `simplexml` extensions (standard on macOS, most Linux distros, and common hosting). PHP only crawls the sitemap and builds reports — it does not need a browser.
- **Node.js 18+** plus the project dependencies (`npm install`) and a Chromium build (`npx playwright install chromium`). This is what actually renders pages and runs axe-core.

No Composer, no Deque account, no API key.

## Options

| Option | Default | Description |
|---|---|---|
| `--sitemap` | *(required)* | A sitemap index, any child sitemap, **or a plain site URL** whose sitemap is auto-discovered |
| `--url` | — | Alias for `--sitemap`: a site URL to auto-discover the sitemap from |
| `--standard` | `wcag21aa` | Preset that sets the rule tags: `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa`, `section508` |
| `--tags` | *(see below)* | Explicit axe-core tag list, overrides `--standard` |
| `--no-best-practice` | off | Drop the `best-practice` tag (test only formal WCAG rules) |
| `--max-urls` | all | Cap pages tested — useful for a trial run |
| `--concurrency` | `4` | Pages scanned in parallel |
| `--timeout` | `30000` | Per-page load timeout, in milliseconds |
| `--node` | `node` | Path to the Node.js binary |
| `--runner` | `./axe-runner.js` | Path to the axe runner script |
| `--output` | `accessibility_report.html` | HTML report path |
| `--csv` | `accessibility_report.csv` | CSV export path |

Default tag list when `--standard` is omitted: `wcag2a,wcag2aa,wcag21a,wcag21aa,best-practice`.

## Choosing concurrency

Each page is fully rendered in a real browser tab and then analysed, so a scan is heavier than a simple HTTP request — budget roughly **1–4 seconds per page**. Browser tabs are also memory-hungry, so concurrency trades speed for RAM:

| Pages | Recommended `--concurrency` | Notes |
|---|---|---|
| < 100 | 4 | Comfortable on any laptop |
| 100–500 | 4–6 | Watch memory; 6 tabs ≈ a few GB |
| 500–2,000 | 6–8 | Run on a machine with 8 GB+ free |
| 2,000+ | 8 | Consider scanning in batches with `--max-urls` |

Unlike API-based tools there is **no daily quota or rate limit** — everything runs locally.

## Report contents

1. **Issues by Impact** — total element instances flagged as Critical / Serious / Moderate / Minor, plus pages-with-issues, clean pages, and unique failing rules. Each impact box links down to the **Results** table.
2. **By Sitemap Group** — issues per content type (Posts, Pages, Products, Collections, …).
3. **Top Issues** — every failing rule ranked by pages affected, with impact badge, an affected-pages bar, total elements, and a link to the Deque University fix guide.
4. **Needs Manual Review** — axe "incomplete" results: things the engine couldn't decide automatically and a human must check.
5. **Results** — every page with its per-impact counts, total, and review count (load failures flagged inline). Click any column header to sort by it; click any row that has issues to expand an inline list of its violations — each rule with its impact, element count and a sample CSS selector.

The CSV mirrors the per-page data for spreadsheets / BI tools.

## Accessibility disclaimer

Automated checks (this tool, axe DevTools, WAVE, Lighthouse) reliably catch only roughly **30–40% of WCAG success criteria**. A clean report is **not** an ADA/WCAG compliance certificate — full conformance also requires manual keyboard navigation, screen-reader, and focus-order testing. Treat the "Needs Manual Review" section as a required human step, and the rest as a prioritised cleanup checklist.

## Scheduling regular scans

Cron example — every Monday at 07:00, with dated report files:

```cron
0 7 * * 1 php /path/to/accessibility_scanner.php --sitemap=https://example.com/sitemap_index.xml --standard=wcag21aa --output=/path/to/reports/a11y-$(date +\%F).html --csv=/path/to/reports/a11y-$(date +\%F).csv
```

## Troubleshooting

**`Node dependencies missing`** — run `npm install` then `npx playwright install chromium` in the project folder.

**`Node.js not found`** — install Node 18+, or point the tool at it with `--node=/full/path/to/node`.

**Browser launch / crash on a locked-down Linux server** — the runner first tries Playwright's default headless build, then automatically falls back to the full Chromium build with the new headless mode. You can also force a specific binary with the `AXE_CHROME_PATH` environment variable.

**Lots of `load error` rows** — the site may be slow or blocking automated browsers; raise `--timeout`, lower `--concurrency`, or check whether a WAF/bot filter is rejecting the requests.

**0 URLs found** — confirm the URL returns XML (`curl -I <sitemap-url>`) and that it's a `<sitemapindex>` or `<urlset>` document.

**`Could not find a sitemap`** — auto-discovery checked `robots.txt` and the common paths but found nothing valid. Locate the sitemap yourself (often linked in `robots.txt` or at `/sitemap.xml`) and pass it directly with `--sitemap=<url>`.

**`Call to undefined function curl_init()` / `simplexml_load_string()`** — install the missing PHP extension, e.g. `sudo apt install php-curl php-xml` on Debian/Ubuntu. macOS's built-in PHP includes both.

## Project structure

```
accessibility-bulk-scanner/
├── accessibility_scanner.php   # orchestrator + report generator (run this)
├── axe-runner.js               # headless axe-core engine (Playwright)
├── package.json                # Node dependencies
├── README.md
├── LICENSE                     # MIT
└── .gitignore
```

## Related

Looking for a **PageSpeed / performance** bulk scanner over a sitemap? That's a separate tool:
[pagespeed-bulk-scanner](https://github.com/simeon-zhelev/pagespeed-bulk-scanner) — same sitemap-driven approach, but measures Core Web Vitals / PageSpeed Insights scores instead of WCAG accessibility.

## License

MIT — see [LICENSE](LICENSE).
