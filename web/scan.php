<?php
/**
 * Server-Sent Events endpoint for the web frontend.
 *
 * Reuses the scanner functions from ../accessibility_scanner.php, runs a scan
 * with the parameters from the query string, streams live progress to the
 * browser, then writes the standalone HTML report + CSV into ./reports/ and
 * emits their URLs.
 *
 * Events emitted (SSE `event:` types):
 *   status   {message}
 *   meta     {total, concurrency, tags, sitemap}
 *   page     {done, total, url, ok, error, counts}
 *   done     {summary, reportUrl, csvUrl, pdfUrl}
 *   error    {message}
 */

// The scanner file carries a `#!/usr/bin/env php` shebang for direct CLI
// execution; outside the CLI that line is echoed as text. Buffer the include
// and discard any such stray output before we send SSE headers.
ob_start();
require __DIR__ . '/../accessibility_scanner.php';
ob_end_clean();

// ── SSE plumbing ─────────────────────────────────────────────────────────────
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
while (ob_get_level() > 0) ob_end_flush();
set_time_limit(0);
ignore_user_abort(true);

// STDERR is only predefined under the CLI SAPI; the scanner writes runner
// progress there. Point it at the server log so those writes don't fatal.
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // disable nginx buffering if proxied

function sse(string $event, array $data): void {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data) . "\n\n";
    @flush();
}

function fail(string $message): void {
    sse('error', ['message' => $message]);
    exit;
}

// ── Build the scan arguments from the query string ───────────────────────────
// Accepts either a direct sitemap URL or a plain site URL/domain (the sitemap
// is then auto-discovered). Both arrive in the same `sitemap` field.
$sitemap = trim((string)($_GET['sitemap'] ?? ''));
if ($sitemap === '') {
    fail('Please provide a website or sitemap URL.');
}
if (!preg_match('#^https?://#i', $sitemap)) {
    $sitemap = 'https://' . ltrim($sitemap, '/');
}
if (!filter_var($sitemap, FILTER_VALIDATE_URL)) {
    fail('Please provide a valid website or sitemap URL (http or https).');
}

// Per-scan token from the browser. Used as the report id and as the key for a
// small sidecar JSON the browser polls after it stops the scan. EventSource is
// one-way, so the browser signals "stop" by closing the stream; this script
// detects that via connection_aborted() and terminates the engine early.
$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[A-Za-z0-9\-]{8,64}$/', $token)) {
    $token = null;
}

$args = [
    'sitemap'          => $sitemap,
    'tags'             => 'wcag2a,wcag2aa,wcag21a,wcag21aa,wcag22aa,best-practice',
    'no-best-practice' => isset($_GET['no-best-practice']) && $_GET['no-best-practice'] !== '0',
    'max-urls'         => isset($_GET['max-urls']) && $_GET['max-urls'] !== ''
                            ? max(1, min(5000, (int)$_GET['max-urls'])) : null,
    'concurrency'      => max(1, min(12, (int)($_GET['concurrency'] ?? 4))),
    'timeout'          => max(1000, min(120000, (int)($_GET['timeout'] ?? 30000))),
    'node'             => 'node',
    'runner'           => dirname(__DIR__) . '/axe-runner.js',
];

// --standard overrides --tags (same rules as the CLI parse_args)
$standard = trim((string)($_GET['standard'] ?? ''));
if ($standard !== '') {
    $tags = standard_to_tags($standard);
    if ($tags === null) {
        fail("Unknown standard '{$standard}'. Use wcag2a, wcag2aa, wcag21a, "
           . "wcag21aa, wcag22aa or section508.");
    }
    $args['tags'] = $args['no-best-practice'] ? $tags : $tags . ',best-practice';
}
if ($args['no-best-practice']) {
    $parts = array_filter(
        array_map('trim', explode(',', $args['tags'])),
        fn($t) => $t !== 'best-practice'
    );
    $args['tags'] = implode(',', $parts);
}

// ── Preflight (Node / runner / dependencies) ─────────────────────────────────
$problem = preflight_problem($args);
if ($problem !== null) {
    fail($problem);
}

// ── Resolve the sitemap (direct URL, or auto-discover from a site URL) ────────
if (!looks_like_sitemap($args['sitemap'])) {
    sse('status', ['message' => 'Looking for the sitemap…']);
}
try {
    ob_start();
    $resolved = discover_sitemap($args['sitemap']);
    ob_end_clean();
} catch (Throwable $e) {
    if (ob_get_level() > 0) ob_end_clean();
    $resolved = null;
}
if ($resolved === null) {
    fail("Could not find a sitemap for '{$args['sitemap']}'. "
       . 'Try entering the sitemap URL directly (e.g. https://example.com/sitemap_index.xml).');
}
$args['sitemap'] = $resolved;

// ── Run the scan ─────────────────────────────────────────────────────────────
try {
    sse('status', ['message' => 'Crawling the sitemap…']);
    // collect_urls() prints crawl progress to stdout; keep it out of the stream.
    ob_start();
    [$urls, $urlToGroup] = collect_urls($args['sitemap'], $args['max-urls']);
    ob_end_clean();
} catch (Throwable $e) {
    fail('Could not read the sitemap: ' . $e->getMessage());
}
if (!$urls) {
    fail('No page URLs found. Verify the sitemap URL is reachable and valid.');
}

$total = count($urls);
sse('status', ['message' => "Found {$total} page" . ($total === 1 ? '' : 's') . '. Launching the browser…']);

[$resultsMap, $engine, $stopped] = scan_all($urls, $urlToGroup, $args, function (array $ev) use ($total) {
    if (($ev['phase'] ?? '') === 'scan-start') {
        sse('meta', [
            'total'       => $ev['total'],
            'concurrency' => $ev['concurrency'],
            'tags'        => $ev['tags'],
        ]);
    } elseif (($ev['phase'] ?? '') === 'page') {
        sse('page', [
            'done'   => $ev['done'],
            'total'  => $total,
            'url'    => $ev['url'],
            'ok'     => $ev['ok'],
            'error'  => $ev['error'],
            'counts' => $ev['counts'],
        ]);
    }
}, function () {
    // Stop as soon as the browser closes the EventSource. connection_aborted()
    // flips to 1 after a flush to the now-dead socket, which our per-page sse()
    // calls provide — so this trips within one page of the user clicking Stop.
    return connection_aborted() === 1;
});

$stopped = $stopped ?? false;

// Preserve sitemap order
$results = [];
foreach ($urls as $u) {
    if (isset($resultsMap[$u])) $results[] = $resultsMap[$u];
}
if (!$results) {
    fail($stopped
        ? 'Scan stopped before any page finished — no report to build.'
        : 'No results returned from the scan. The browser engine may have failed to launch.');
}

// ── Build the report + CSV and write them where the browser can fetch them ────
sse('status', ['message' => $stopped
    ? 'Scan stopped. Building a report for the pages scanned so far…'
    : 'Building the report…']);
$agg = aggregate($results);
$generatedAt = date('Y-m-d H:i');

// Use the browser's token as the report id when present, so it can fetch the
// finished report by name after stopping (when the SSE `done` never arrives).
$id      = $token ?? (date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 6));
$htmlRel = "reports/{$id}.html";
$csvRel  = "reports/{$id}.csv";
$pdfRel  = "reports/{$id}.pdf";

file_put_contents(__DIR__ . '/' . $htmlRel,
    build_html($results, $urlToGroup, $agg, $args['sitemap'],
               $generatedAt, $args['tags'], $engine));
file_put_contents(__DIR__ . '/' . $csvRel,
    build_csv($results, $urlToGroup));

// Render a PDF from the HTML report (best-effort; report still works without).
// Skipped on a stopped scan — the user asked to stop, so don't make them wait.
$pdfOk = false;
if (!$stopped) {
    sse('status', ['message' => 'Rendering the PDF…']);
    $pdfOk = render_pdf(
        __DIR__ . '/' . $htmlRel,
        __DIR__ . '/' . $pdfRel,
        $args['node'],
        dirname(__DIR__) . '/html-to-pdf.js'
    );
}

$t = $agg['totals'];
$donePayload = [
    'stopped'   => $stopped,
    'reportUrl' => $htmlRel,
    'csvUrl'    => $csvRel,
    'pdfUrl'    => $pdfOk ? $pdfRel : null,
    'summary'   => [
        'pages'           => count($results),
        'pagesWithIssues' => $agg['pagesWithIssues'],
        'cleanPages'      => $agg['cleanPages'],
        'errorPages'      => $agg['errorPages'],
        'uniqueRules'     => $agg['uniqueRules'],
        'critical'        => $t['critical'],
        'serious'         => $t['serious'],
        'moderate'        => $t['moderate'],
        'minor'           => $t['minor'],
        'violations'      => $t['violations'],
        'engine'          => $engine,
    ],
];

// Write a sidecar with the same payload. When the user stops a scan the browser
// has closed the stream, so the `done` event below never reaches it — instead
// it polls this file (keyed by the token) to pick up the partial report.
if ($token !== null) {
    file_put_contents(__DIR__ . "/reports/{$token}.status.json", json_encode($donePayload));
}

sse('done', $donePayload);
