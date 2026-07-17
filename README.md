# Accessibility Bulk Scanner

Audit the accessibility of **every page of a website** against WCAG, in parallel — and get **two reports from one scan**: a detailed, tabbed **HTML dashboard** for developers and a concise, client-ready **PDF**. Track progress over time by comparing any two scans.

Point it at a website address and it **finds the XML sitemap automatically** (via `robots.txt` and the usual locations); if there isn't one, it **crawls the site's links** instead — or give it a sitemap URL directly. Works with **WordPress (Yoast SEO)**, **Shopify**, and any site, sitemap or not.

## How it works

Accessibility rules only mean anything against a **rendered** page, so this tool is split in two:

- **`accessibility_scanner.php`** — the orchestrator. Discovers the pages to test (XML sitemap, or a same-origin **link crawl** when there's no sitemap), drives the scan, aggregates results, and writes the reports: a detailed HTML dashboard, a concise client PDF, a CSV, a JSON snapshot, and a console summary. This is the part you run.
- **`axe-runner.js`** — a small headless-browser engine. Launches Chromium (via Playwright) once and runs the open-source **[axe-core](https://github.com/dequelabs/axe-core)** engine (currently 4.12.1) against each page, streaming results back to PHP as NDJSON.
- **`html-to-pdf.js`** — PDF helper. Renders the concise **client** report to PDF in the same headless Chromium (used by `--pdf` and the web UI's Download PDF).

axe-core is the same engine inside **Deque axe DevTools**, so the rule set and results match what you'd see there. **No Deque license or API key is required.** If you later license a commercial runner, you can swap it in with `--runner=PATH` as long as it emits the same NDJSON.

## Quick start

```bash
# 1. Install the browser engine (one time)
npm install
npx playwright install chromium

# 2a. Easiest — just give it the site URL (sitemap auto-discovered; WCAG 2.2 AA by default)
php accessibility_scanner.php --url=https://example.com

# 2b. Point straight at a sitemap, and also export the concise client PDF
php accessibility_scanner.php \
  --sitemap=https://example.com/sitemap_index.xml \
  --concurrency=4 \
  --output=report.html \
  --pdf            # concise client report → report.pdf (detailed view stays in report.html)

# 2c. No sitemap? Crawl the site's links instead
php accessibility_scanner.php --url=https://example.com --crawl

# 2d. Track improvement — compare against a previous run's snapshot
php accessibility_scanner.php --url=https://example.com --compare=last-run.json
```

> Every run also writes a small **JSON snapshot** next to the HTML report (e.g.
> `report.json`) — the durable record used by `--compare` to show what changed.

> **Auto-discovery & crawl fallback:** `--sitemap` and `--url` both accept either
> a plain site address (e.g. `https://example.com`) or a direct sitemap URL. Given
> a site address, the scanner reads `robots.txt` for `Sitemap:` directives and then
> probes common paths (`/sitemap_index.xml`, `/sitemap.xml`, `/wp-sitemap.xml`, …).
> **If no sitemap is found, it automatically crawls same-origin `<a href>` links**
> from the start URL (capped at 2000 pages). Pass `--crawl` to force crawling even
> when a sitemap exists, or `--crawl-depth=N` to limit how deep it follows links.

**Tip:** for a first run on a large site, do a trial with `--max-urls=20` to confirm everything works before scanning all pages.

## Web UI

Prefer a browser to the command line? A small, responsive landing page is included in `web/`. Enter a **website address** (the sitemap is found automatically, or the site is crawled) or a sitemap URL, pick your options, and it streams **live per-page progress** with the full report inline.

```bash
# Install once (same as above)
npm install
npx playwright install chromium

# Start the built-in PHP web server, then open http://127.0.0.1:8081
php -S 127.0.0.1:8081 -t web
```

In the browser you can:

- **Watch progress live** and **Stop** a scan mid-run — you still get a report for the pages already scanned.
- **Open Full Report** — the detailed developer HTML (shown inline and openable in a new tab).
- **Download PDF** — the concise, client-ready report.
- **Crawl the site directly** (checkbox) when there's no sitemap, and **Compare against a previous run** (dropdown) to measure improvement.

Defaults to **WCAG 2.2 AA**. It reuses the exact same engine as the CLI — `accessibility_scanner.php` + `axe-runner.js` — so results are identical. Reports and per-run JSON snapshots are written to `web/reports/` (git-ignored); the snapshots populate the compare dropdown.

> **Testing from a phone on your network:** bind the server to all interfaces
> with `php -S 0.0.0.0:8081 -t web`, then open `http://<your-computer-ip>:8081`
> on the phone (same Wi-Fi). Find the IP with `ipconfig getifaddr en0` (macOS).

> **Note:** the web UI runs scans on demand and renders arbitrary pages in a headless browser, so keep it bound to `127.0.0.1` / a trusted network rather than exposing it publicly.

## Requirements

- **PHP 7.4+** with the `curl` and `simplexml` extensions (standard on macOS, most Linux distros, and common hosting). PHP only crawls the sitemap and builds reports — it does not need a browser.
- **Node.js 18+** plus the project dependencies (`npm install`) and a Chromium build (`npx playwright install chromium`). This is what actually renders pages and runs axe-core.

No Composer, no Deque account, no API key.

## Options

| Option | Default | Description |
|---|---|---|
| `--sitemap` | *(required)* | A sitemap index, any child sitemap, **or a plain site URL** whose sitemap is auto-discovered (with crawl fallback) |
| `--url` | — | Alias for `--sitemap`: a site URL to auto-discover the sitemap from |
| `--crawl` | off | Skip sitemap detection and discover pages by **crawling** same-origin links from the start URL (used automatically when no sitemap is found) |
| `--crawl-depth` | `0` | Limit crawl link depth (`0` = unlimited, bounded by `--max-urls`, or 2000 pages when unset) |
| `--standard` | `wcag22aa` | Preset that sets the rule tags: `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa`, `section508` |
| `--tags` | *(see below)* | Explicit axe-core tag list, overrides `--standard` |
| `--no-best-practice` | off | Drop the `best-practice` tag (test only formal WCAG rules) |
| `--max-urls` | all | Cap pages tested — useful for a trial run |
| `--concurrency` | `4` | Pages scanned in parallel |
| `--timeout` | `30000` | Per-page load timeout, in milliseconds |
| `--node` | `node` | Path to the Node.js binary |
| `--runner` | `./axe-runner.js` | Path to the axe runner script |
| `--output` | `accessibility_report.html` | Detailed **HTML** report path (the developer view) |
| `--csv` | `accessibility_report.csv` | CSV export path |
| `--pdf[=FILE]` | off | Also export the concise **client PDF** (rendered via headless Chromium). Bare `--pdf` derives the name from `--output` (e.g. `report.pdf`) |
| `--snapshot[=FILE]` | *(on)* | Write a machine-readable **JSON snapshot** for tracking over time. Defaults on, derived from `--output` (e.g. `report.json`) |
| `--compare=FILE` | — | Diff this run against a previous snapshot and add a **"Changes since baseline"** section (fixed / new / net delta) |

Default tag list when `--standard` is omitted: `wcag2a,wcag2aa,wcag21a,wcag21aa,wcag22aa,best-practice`.

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

Every scan produces **two views**, plus machine-readable exports.

### Detailed HTML report — for developers (`--output`, "Open Full Report")

Findings are split into two tabs — **Code related** (issues fixable in markup/templates) and **Design related** (colour-contrast, which needs design decisions). Each tab contains:

1. **Summary** — element instances by impact (Critical / Serious / Moderate / Minor), pages affected, and unique failing rules.
2. **Top Issues** — every failing rule ranked by pages affected, with impact badge, affected-pages bar, total elements, and a link to the Deque University fix guide.
3. **By Sitemap Group** — issues per content type (Posts, Pages, Products, Collections, …).
4. **Needs Manual Review** — axe "incomplete" results the engine couldn't decide automatically.
5. **Results** — every page with its per-impact counts (sortable columns; click a row to expand its violations with a sample selector).

### Client PDF — for clients (`--pdf`, "Download PDF")

A concise, presentable document: an at-a-glance **verdict**, findings **grouped by severity** as short plain-language lists with recommendations, **code improvements** as the primary in-scope section, and colour-contrast surfaced as a lighter **"separate scope"** section. No per-page tables or rule ids.

### Tracking improvement

Every run writes a **JSON snapshot**. Pass a prior snapshot via `--compare` (CLI) or pick a previous run in the web dropdown, and both reports gain a comparison section — **"Changes since baseline"** in the detailed HTML, **"Progress since your last review"** in the client PDF — showing rules fixed / new and the net delta, split by code vs design.

The **CSV** mirrors the per-page data (with `code_violations` / `design_violations` columns) for spreadsheets / BI tools.

## Accessibility disclaimer

Automated checks (this tool, axe DevTools, WAVE, Lighthouse) reliably catch only roughly **30–40% of WCAG success criteria**. A clean report is **not** an ADA/WCAG compliance certificate — full conformance also requires manual keyboard navigation, screen-reader, and focus-order testing. Treat the "Needs Manual Review" section as a required human step, and the rest as a prioritised cleanup checklist.

## Scheduling regular scans

Cron example — every Monday at 07:00, with dated report files (defaults to WCAG 2.2 AA; a dated `.json` snapshot is written alongside for week-over-week tracking):

```cron
0 7 * * 1 php /path/to/accessibility_scanner.php --sitemap=https://example.com/sitemap_index.xml --output=/path/to/reports/a11y-$(date +\%F).html --csv=/path/to/reports/a11y-$(date +\%F).csv --pdf
```

Add `--compare=/path/to/reports/a11y-<last-week>.json` to include a "Changes since baseline" section against the previous run.

## Troubleshooting

**`Node dependencies missing`** — run `npm install` then `npx playwright install chromium` in the project folder.

**`Node.js not found`** — install Node 18+, or point the tool at it with `--node=/full/path/to/node`.

**Browser launch / crash on a locked-down Linux server** — the runner first tries Playwright's default headless build, then automatically falls back to the full Chromium build with the new headless mode. You can also force a specific binary with the `AXE_CHROME_PATH` environment variable.

**Lots of `load error` rows** — the site may be slow or blocking automated browsers; raise `--timeout`, lower `--concurrency`, or check whether a WAF/bot filter is rejecting the requests.

**No page URLs found** — the site was unreachable, blocked automated requests, or (when crawling) had no crawlable same-origin links. Check the URL loads, then try a sitemap URL directly with `--sitemap=<url>`, or `--crawl` from a page that links to the rest of the site.

**Only some pages scanned when crawling** — the crawl is capped at 2000 pages by default and only follows same-origin `<a href>` links. Raise the cap with `--max-urls=N`, or scan from a sitemap for complete coverage.

**`Call to undefined function curl_init()` / `simplexml_load_string()`** — install the missing PHP extension, e.g. `sudo apt install php-curl php-xml` on Debian/Ubuntu. macOS's built-in PHP includes both.

## Project structure

```
accessibility-bulk-scanner/
├── accessibility_scanner.php   # orchestrator + report generators (run this)
├── axe-runner.js               # headless axe-core engine (Playwright)
├── html-to-pdf.js              # HTML→PDF helper (Playwright; used by --pdf)
├── web/                        # optional browser UI (php -S … -t web)
│   ├── index.php               # landing page / scan form
│   ├── scan.php                # SSE endpoint (live progress + report build)
│   ├── reports.php             # lists saved snapshots for the compare dropdown
│   └── reports/                # generated reports + JSON snapshots (git-ignored)
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
