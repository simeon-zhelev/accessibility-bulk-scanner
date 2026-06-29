#!/usr/bin/env php
<?php
/**
 * Accessibility Bulk Scanner (PHP orchestrator + open-source axe-core engine)
 * ---------------------------------------------------------------------------
 * Crawls a sitemap (Yoast / Shopify / generic / sitemap-index / .xml.gz),
 * then renders every page in a headless browser and runs the open-source
 * axe-core accessibility engine against each one. Produces:
 *   - a self-contained light-themed HTML dashboard (accessibility_report.html)
 *   - a CSV export                                 (accessibility_report.csv)
 *   - a console summary
 *
 * Architecture
 *   PHP handles sitemap crawling, orchestration, aggregation and report generation.
 *   Because accessibility rules only mean anything against a *rendered* DOM
 *   — which PHP cannot produce — the actual axe-core run happens in a small
 *   Node helper (axe-runner.js) that drives headless Chromium via Playwright.
 *   PHP launches it once, streams back NDJSON results, and reports.
 *
 *   The engine is open-source axe-core (the same engine inside Deque axe
 *   DevTools). No Deque license or API key is required. A hook is left so a
 *   licensed runner could be swapped in later via --runner.
 *
 * Requirements
 *   - PHP 7.4+ with curl + simplexml (standard on macOS / Linux)
 *   - Node.js 18+  and, in the project folder:  npm install
 *                                               npx playwright install chromium
 *
 * Usage
 *   php accessibility_scanner.php \
 *       --sitemap=https://example.com/sitemap_index.xml \
 *       --standard=wcag21aa \
 *       --concurrency=4 \
 *       --max-urls=50 \
 *       --output=report.html \
 *       --csv=report.csv
 */

// ─────────────────────────────────────────────────────────────────────────────
//  CLI arguments
// ─────────────────────────────────────────────────────────────────────────────

/** Map a friendly --standard value to an axe-core tag list. */
function standard_to_tags(string $standard): ?string {
    $map = [
        'wcag2a'    => 'wcag2a',
        'wcag2aa'   => 'wcag2a,wcag2aa',
        'wcag21a'   => 'wcag2a,wcag21a',
        'wcag21aa'  => 'wcag2a,wcag2aa,wcag21a,wcag21aa',
        'wcag22aa'  => 'wcag2a,wcag2aa,wcag21a,wcag21aa,wcag22aa',
        'section508'=> 'section508',
    ];
    $key = strtolower(str_replace([' ', '-', '_', '.'], '', $standard));
    return $map[$key] ?? null;
}

function parse_args(array $argv): array {
    $defaults = [
        'sitemap'     => null,
        'url'         => null,         // site URL — sitemap is auto-discovered
        'tags'        => 'wcag2a,wcag2aa,wcag21a,wcag21aa,best-practice',
        'standard'    => null,         // shortcut that overrides --tags
        'no-best-practice' => false,
        'max-urls'    => null,
        'concurrency' => 4,
        'timeout'     => 30000,        // ms per page (Node side)
        'node'        => 'node',
        'runner'      => __DIR__ . '/axe-runner.js',
        'output'      => 'accessibility_report.html',
        'csv'         => 'accessibility_report.csv',
    ];
    $opts = getopt('', [
        'sitemap:', 'url:', 'tags:', 'standard:', 'no-best-practice',
        'max-urls:', 'concurrency:', 'timeout:', 'node:', 'runner:',
        'output:', 'csv:', 'help',
    ]);

    // --url is an alias: pass a site URL and the sitemap is auto-discovered.
    if (empty($opts['sitemap']) && !empty($opts['url'])) {
        $opts['sitemap'] = $opts['url'];
    }

    if (isset($opts['help']) || empty($opts['sitemap'])) {
        echo <<<HELP

Bulk accessibility (WCAG) scanner — axe-core over an XML sitemap

Usage:
  php accessibility_scanner.php --sitemap=URL [options]
  php accessibility_scanner.php --url=https://example.com   (auto-find sitemap)

Options:
  --sitemap=URL        sitemap_index.xml, any child sitemap, OR a plain site
                       URL/domain whose sitemap is auto-discovered (required)
  --url=URL            Alias for --sitemap; a site URL whose sitemap is
                       auto-discovered via robots.txt + common paths
  --standard=S         Convenience preset, sets --tags:
                         wcag2a | wcag2aa | wcag21a | wcag21aa (default) |
                         wcag22aa | section508
  --tags=LIST          Explicit axe-core tag list, comma-separated
                       (default: wcag2a,wcag2aa,wcag21a,wcag21aa,best-practice)
  --no-best-practice   Drop the 'best-practice' tag (WCAG rules only)
  --max-urls=N         Cap total pages scanned (great for a trial run)
  --concurrency=N      Pages scanned in parallel (default: 4)
  --timeout=MS         Per-page load timeout in ms (default: 30000)
  --node=PATH          Node.js binary (default: node)
  --runner=PATH        axe runner script (default: ./axe-runner.js)
  --output=FILE        HTML report path (default: accessibility_report.html)
  --csv=FILE           CSV export path  (default: accessibility_report.csv)
  --help               Show this help

Examples:
  # Auto-discover the sitemap from just the site URL
  php accessibility_scanner.php --url=https://example.com --standard=wcag21aa

  # Full WCAG 2.1 AA scan of a WordPress/Yoast site
  php accessibility_scanner.php \\
      --sitemap=https://example.com/sitemap_index.xml --standard=wcag21aa

  # Quick 20-page trial, 6 parallel pages
  php accessibility_scanner.php \\
      --sitemap=https://store.com/sitemap.xml --max-urls=20 --concurrency=6

HELP;
        exit(empty($opts['sitemap']) && !isset($opts['help']) ? 1 : 0);
    }

    $args = array_merge($defaults, $opts);
    $args['max-urls']    = isset($opts['max-urls']) ? (int)$opts['max-urls'] : null;
    $args['concurrency'] = max(1, (int)$args['concurrency']);
    $args['timeout']     = max(1000, (int)$args['timeout']);
    $args['no-best-practice'] = isset($opts['no-best-practice']);

    // --standard overrides --tags
    if (!empty($opts['standard'])) {
        $tags = standard_to_tags($opts['standard']);
        if ($tags === null) {
            fwrite(STDERR, "❌  Unknown --standard '{$opts['standard']}'. "
                . "Use wcag2a, wcag2aa, wcag21a, wcag21aa, wcag22aa or section508.\n");
            exit(1);
        }
        // Keep best-practice unless explicitly dropped
        $args['tags'] = $args['no-best-practice'] ? $tags : $tags . ',best-practice';
    }
    if ($args['no-best-practice']) {
        $parts = array_filter(
            array_map('trim', explode(',', $args['tags'])),
            fn($t) => $t !== 'best-practice'
        );
        $args['tags'] = implode(',', $parts);
    }
    return $args;
}

// ─────────────────────────────────────────────────────────────────────────────
//  HTTP helper (sitemap fetch only)
// ─────────────────────────────────────────────────────────────────────────────

function http_get(string $url, int $timeout = 30): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'AccessibilityBulkScanner/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("cURL error: $err");
    }
    if ($code >= 400) {
        throw new RuntimeException("HTTP $code for $url");
    }
    if (strncmp($body, "\x1f\x8b", 2) === 0 && function_exists('gzdecode')) {
        $decoded = gzdecode($body);
        if ($decoded !== false) $body = $decoded;
    }
    return $body;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Sitemap discovery (accept a plain site URL and find its sitemap)
// ─────────────────────────────────────────────────────────────────────────────

/** Does this URL already point at a sitemap (rather than a site root)? */
function looks_like_sitemap(string $url): bool {
    $path = strtolower((string)parse_url($url, PHP_URL_PATH));
    return (bool)preg_match('/\.xml(\.gz)?$/', $path) || strpos($path, 'sitemap') !== false;
}

/** Is this XML string a valid sitemap or sitemap index? */
function is_sitemap_xml(string $body): bool {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    libxml_clear_errors();
    return $xml !== false && in_array($xml->getName(), ['sitemapindex', 'urlset'], true);
}

/**
 * Resolve a user-supplied URL into a usable sitemap URL.
 *
 * Accepts either a direct sitemap URL (returned as-is) or a plain site URL /
 * domain, in which case it auto-discovers the sitemap by (1) reading robots.txt
 * for `Sitemap:` directives, then (2) probing a list of common sitemap paths.
 * Returns the discovered sitemap URL, or null if none could be confirmed.
 * $log is an optional callback for progress lines (defaults to echo).
 */
function discover_sitemap(string $input, ?callable $log = null): ?string {
    $say = function (string $m) use ($log) { $log ? $log($m) : print($m . "\n"); };

    $input = trim($input);
    if ($input === '') return null;
    if (!preg_match('#^https?://#i', $input)) {
        $input = 'https://' . ltrim($input, '/');
    }

    // Already a sitemap URL? Use it directly.
    if (looks_like_sitemap($input)) return $input;

    $say("🔎 Auto-discovering sitemap for $input …");

    $parts  = parse_url($input);
    $host   = $parts['host'] ?? '';
    if ($host === '') return null;
    $origin = ($parts['scheme'] ?? 'https') . '://' . $host
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

    $candidates = [];

    // 1 — robots.txt Sitemap: directives (the authoritative source)
    try {
        $robots = http_get($origin . '/robots.txt', 10);
        if (preg_match_all('/^\s*Sitemap:\s*(\S+)/im', $robots, $m)) {
            foreach ($m[1] as $loc) {
                $loc = trim($loc);
                if ($loc !== '') $candidates[] = $loc;
            }
            if ($candidates) $say('   robots.txt lists ' . count($candidates) . ' sitemap(s).');
        }
    } catch (Throwable $e) {
        // No robots.txt — fall through to common paths.
    }

    // 2 — Common sitemap locations (WordPress/Yoast, Shopify, generic)
    foreach ([
        '/sitemap_index.xml', '/sitemap-index.xml', '/sitemap.xml',
        '/wp-sitemap.xml', '/sitemap.xml.gz', '/sitemap/sitemap.xml',
    ] as $p) {
        $candidates[] = $origin . $p;
    }

    // Probe each candidate; return the first that is a real sitemap.
    $seen = [];
    foreach ($candidates as $url) {
        if (isset($seen[$url])) continue;
        $seen[$url] = true;
        try {
            $body = http_get($url, 12);
        } catch (Throwable $e) {
            continue;
        }
        if (is_sitemap_xml($body)) {
            $say("   ✓ Found sitemap: $url");
            return $url;
        }
    }

    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Sitemap crawling (namespace-agnostic; Yoast / Shopify / generic compatible)
// ─────────────────────────────────────────────────────────────────────────────

function sitemap_group_name(string $url): string {
    $stem = basename(parse_url($url, PHP_URL_PATH) ?? $url);
    $stem = preg_replace('/\.xml(\.gz)?$/i', '', $stem);
    $stem = preg_replace('/[-_]sitemap$/i', '', $stem);
    $stem = preg_replace('/^sitemap[-_]?/i', '', $stem);
    $stem = preg_replace('/[-_]\d+$/', '', $stem);
    $stem = preg_replace('/^app_/i', '', $stem);
    $stem = str_replace(['-', '_'], ' ', $stem);
    $stem = ucwords(trim($stem));
    return $stem !== '' ? $stem : 'Pages';
}

/** Recursively expand a sitemap or sitemap-index. Returns [urls, urlToGroup]. */
function collect_urls(string $sitemapUrl, ?int $maxUrls = null): array {
    $urls = [];
    $urlToGroup = [];
    $visited = [];

    $crawl = function (string $url, string $group) use (
        &$crawl, &$urls, &$urlToGroup, &$visited, $maxUrls
    ): void {
        if (isset($visited[$url])) return;
        $visited[$url] = true;
        echo "  ↳ Fetching sitemap: $url\n";

        try {
            $content = http_get($url, 20);
        } catch (Throwable $e) {
            echo "    ⚠  Could not fetch $url: {$e->getMessage()}\n";
            return;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        libxml_clear_errors();
        if ($xml === false) {
            echo "    ⚠  Could not parse XML from $url\n";
            return;
        }

        $root = $xml->getName();
        if ($root === 'sitemapindex') {
            foreach ($xml->sitemap as $sm) {
                if ($maxUrls && count($urls) >= $maxUrls) return;
                $childUrl = trim((string)$sm->loc);
                if ($childUrl === '') continue;
                $crawl($childUrl, sitemap_group_name($childUrl));
            }
        } elseif ($root === 'urlset') {
            foreach ($xml->url as $u) {
                if ($maxUrls && count($urls) >= $maxUrls) return;
                $pageUrl = trim((string)$u->loc);
                if ($pageUrl === '' || isset($urlToGroup[$pageUrl])) continue;
                $urls[] = $pageUrl;
                $urlToGroup[$pageUrl] = $group;
            }
        } else {
            echo "    ⚠  Unrecognised root element <$root> in $url\n";
        }
    };

    echo "\n📄 Collecting URLs from sitemap …\n";
    $crawl($sitemapUrl, sitemap_group_name($sitemapUrl));

    $groupCount = count(array_unique(array_values($urlToGroup)));
    echo '   Found ' . count($urls) . " unique page URLs across $groupCount sitemap group(s)\n\n";

    if ($maxUrls !== null) {
        $urls = array_slice($urls, 0, $maxUrls);
    }
    return [$urls, $urlToGroup];
}

// ─────────────────────────────────────────────────────────────────────────────
//  axe-core engine via the Node runner (streamed NDJSON)
// ─────────────────────────────────────────────────────────────────────────────

const IMPACTS = ['critical', 'serious', 'moderate', 'minor'];

/**
 * Check the runtime prerequisites. Returns a human-readable problem string,
 * or null if everything is in place. Shared by the CLI preflight() and the
 * web frontend so both report the same diagnostics.
 */
function preflight_problem(array $args): ?string {
    // Node present?
    $ver = trim((string)@shell_exec(escapeshellarg($args['node']) . ' --version 2>/dev/null'));
    if ($ver === '') {
        return "Node.js not found (looked for '{$args['node']}'). "
             . "Install Node 18+ and re-run, or pass --node=/path/to/node.";
    }
    // Runner present?
    if (!is_file($args['runner'])) {
        return "axe runner not found at {$args['runner']} (use --runner=PATH).";
    }
    // Dependencies installed?
    $nm = dirname($args['runner']) . '/node_modules/@axe-core/playwright';
    if (!is_dir($nm)) {
        return "Node dependencies missing. In " . dirname($args['runner']) . " run:\n"
             . "  npm install\n"
             . "  npx playwright install chromium";
    }
    return null;
}

function preflight(array $args): void {
    $problem = preflight_problem($args);
    if ($problem !== null) {
        fwrite(STDERR, "❌  " . str_replace("\n", "\n    ", $problem) . "\n");
        exit(1);
    }
}

/**
 * Launch the Node runner once and stream back NDJSON.
 * Returns [results(map url=>row), engineVersion].
 */
function scan_all(array $urls, array $urlToGroup, array $args, ?callable $onEvent = null): array {
    $payload = [];
    foreach ($urls as $u) {
        $payload[] = ['url' => $u, 'group' => $urlToGroup[$u] ?? null];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'axe_urls_');
    file_put_contents($tmp, json_encode($payload));

    $cmd = implode(' ', array_map('escapeshellarg', [
        $args['node'], $args['runner'],
        '--input', $tmp,
        '--concurrency', (string)$args['concurrency'],
        '--tags', $args['tags'],
        '--timeout', (string)$args['timeout'],
    ]));

    if ($onEvent) {
        $onEvent(['phase' => 'scan-start', 'total' => count($urls),
                  'concurrency' => $args['concurrency'], 'tags' => $args['tags']]);
    } else {
        echo "⚡  Scanning " . count($urls) . " pages with axe-core "
           . "(concurrency {$args['concurrency']}, tags {$args['tags']}) …\n\n";
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname($args['runner']));
    if (!is_resource($proc)) {
        @unlink($tmp);
        fwrite(STDERR, "❌  Failed to launch the Node runner.\n");
        exit(1);
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $results = [];
    $engine  = null;
    $buf     = '';
    $open    = [1 => $pipes[1], 2 => $pipes[2]];

    $handleLine = function (string $line) use (&$results, &$engine, $onEvent): void {
        $line = trim($line);
        if ($line === '') return;
        $obj = json_decode($line, true);
        if (!is_array($obj)) return;
        $type = $obj['type'] ?? '';
        if ($type === 'result') {
            if ($engine === null && !empty($obj['engine'])) $engine = $obj['engine'];
            $results[$obj['url']] = $obj;
            if ($onEvent) {
                $onEvent([
                    'phase'   => 'page',
                    'done'    => count($results),
                    'url'     => $obj['url'],
                    'ok'      => $obj['ok'] ?? false,
                    'error'   => $obj['error'] ?? null,
                    'counts'  => $obj['counts'] ?? null,
                ]);
            }
        }
    };

    while ($open) {
        $read = $open; $w = null; $e = null;
        if (@stream_select($read, $w, $e, 1, 0) === false) break;
        foreach ($read as $stream) {
            $chunk = fread($stream, 65536);
            if ($chunk === '' || $chunk === false) {
                if (feof($stream)) {
                    $key = array_search($stream, $open, true);
                    if ($key !== false) { fclose($stream); unset($open[$key]); }
                }
                continue;
            }
            if ($stream === $pipes[2]) {
                fwrite(STDERR, $chunk);          // pass runner progress through
            } else {
                $buf .= $chunk;
                while (($nl = strpos($buf, "\n")) !== false) {
                    $handleLine(substr($buf, 0, $nl));
                    $buf = substr($buf, $nl + 1);
                }
            }
        }
    }
    if ($buf !== '') $handleLine($buf);

    $code = proc_close($proc);
    @unlink($tmp);
    if ($code !== 0 && !$results) {
        fwrite(STDERR, "❌  Runner exited with code $code and produced no results.\n");
        exit(1);
    }
    return [$results, $engine];
}

// ─────────────────────────────────────────────────────────────────────────────
//  Aggregation
// ─────────────────────────────────────────────────────────────────────────────

function aggregate(array $results): array {
    $tot = ['critical'=>0,'serious'=>0,'moderate'=>0,'minor'=>0,
            'violations'=>0,'incomplete'=>0];
    $pagesWithIssues = 0; $cleanPages = 0; $errorPages = 0; $okPages = 0;

    // rule id => [help, impact, helpUrl, pages, elements]
    $rules = [];
    $review = [];

    foreach ($results as $r) {
        if (empty($r['ok'])) { $errorPages++; continue; }
        $okPages++;
        $c = $r['counts'] ?? [];
        foreach (IMPACTS as $imp) $tot[$imp] += (int)($c[$imp] ?? 0);
        $tot['violations'] += (int)($c['violations'] ?? 0);
        $tot['incomplete'] += (int)($c['incomplete'] ?? 0);

        if ((int)($c['violations'] ?? 0) > 0) $pagesWithIssues++;
        else $cleanPages++;

        foreach (($r['violations'] ?? []) as $v) {
            $id = $v['id'];
            if (!isset($rules[$id])) {
                $rules[$id] = ['help'=>$v['help'],'impact'=>$v['impact'],
                               'helpUrl'=>$v['helpUrl'],'pages'=>0,'elements'=>0];
            }
            $rules[$id]['pages']++;
            $rules[$id]['elements'] += (int)($v['nodeCount'] ?? 1);
        }
        foreach (($r['incomplete'] ?? []) as $v) {
            $id = $v['id'];
            if (!isset($review[$id])) {
                $review[$id] = ['help'=>$v['help'],'impact'=>$v['impact'],
                                'helpUrl'=>$v['helpUrl'],'pages'=>0,'elements'=>0];
            }
            $review[$id]['pages']++;
            $review[$id]['elements'] += (int)($v['nodeCount'] ?? 1);
        }
    }

    $impactRank = ['critical'=>0,'serious'=>1,'moderate'=>2,'minor'=>3, null=>4];
    $cmp = function ($a, $b) use ($impactRank) {
        return $b['pages'] <=> $a['pages']
            ?: ($impactRank[$a['impact']] ?? 5) <=> ($impactRank[$b['impact']] ?? 5)
            ?: $b['elements'] <=> $a['elements'];
    };
    uasort($rules, $cmp);
    uasort($review, $cmp);

    return [
        'totals' => $tot,
        'pagesWithIssues' => $pagesWithIssues,
        'cleanPages' => $cleanPages,
        'errorPages' => $errorPages,
        'okPages' => $okPages,
        'uniqueRules' => count($rules),
        'rules' => $rules,
        'review' => $review,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
//  HTML report helpers
// ─────────────────────────────────────────────────────────────────────────────

function impact_color(?string $impact): string {
    switch ($impact) {
        case 'critical': return '#ef4444';
        case 'serious':  return '#f97316';
        case 'moderate': return '#f59e0b';
        case 'minor':    return '#3b82f6';
        default:         return '#94a3b8';
    }
}

/**
 * Darker impact shades for COLOURED TEXT on the light theme. The vivid
 * impact_color() values are tuned for white-on-colour badges; as text on a
 * white background several of them fall below WCAG contrast, so number cells
 * and big scores use these higher-contrast variants instead.
 */
function impact_text_color(?string $impact): string {
    switch ($impact) {
        case 'critical': return '#dc2626';
        case 'serious':  return '#c2410c';
        case 'moderate': return '#b45309';
        case 'minor':    return '#2563eb';
        default:         return '#64748b';
    }
}

function impact_badge(?string $impact): string {
    $c = impact_color($impact);
    $label = $impact ? ucfirst($impact) : 'n/a';
    return "<span style=\"background:$c;color:#fff;padding:2px 8px;border-radius:12px;"
         . "font-weight:700;font-size:0.72rem;text-transform:capitalize\">$label</span>";
}

function count_badge(int $n, string $color): string {
    $dim = $n === 0 ? 'opacity:.35;' : '';
    return "<span style=\"$dim color:$color;font-weight:700\">$n</span>";
}

function summary_cards(array $agg, int $totalPages): string {
    $t = $agg['totals'];
    $cards = [
        ['Critical', $t['critical'], impact_text_color('critical')],
        ['Serious',  $t['serious'],  impact_text_color('serious')],
        ['Moderate', $t['moderate'], impact_text_color('moderate')],
        ['Minor',    $t['minor'],    impact_text_color('minor')],
    ];
    $html = '';
    foreach ($cards as [$label, $n, $color]) {
        $html .= <<<CARD

      <div class="card">
        <div class="card-label">$label</div>
        <div class="card-score" style="color:$color">$n</div>
        <div class="card-sub">element instances</div>
      </div>
CARD;
    }
    $green = '#15803d';
    $pwi = $agg['pagesWithIssues'];
    $clean = $agg['cleanPages'];
    $rules = $agg['uniqueRules'];
    $errs = $agg['errorPages'];
    $errLine = $errs > 0 ? " &nbsp;|&nbsp; ⚠ $errs page(s) failed to load" : '';

    return <<<HTML
<div class="section-title">♿ Issues by Impact</div>
<div class="cards">$html</div>
<div class="stats">
  <span><strong style="color:#dc2626">$pwi</strong> / $totalPages pages with issues</span>
  <span><strong style="color:$green">$clean</strong> clean pages</span>
  <span><strong>$rules</strong> unique rules failing</span>$errLine
</div>
HTML;
}

function top_issues_table(array $agg, int $totalPages): string {
    $rules = $agg['rules'];
    if (!$rules) {
        return '<div class="section-title">🏆 Top Issues</div>'
             . '<p style="color:#15803d;font-size:0.9rem">No automated WCAG '
             . 'violations detected. Manual testing is still required for full coverage.</p>';
    }
    $rows = '';
    foreach ($rules as $id => $a) {
        $help   = htmlspecialchars($a['help']);
        $idEsc  = htmlspecialchars($id);
        $url    = htmlspecialchars($a['helpUrl']);
        $title  = $url !== ''
            ? "<a href=\"$url\" target=\"_blank\" rel=\"noopener\">$help</a>"
            : $help;
        $pct    = round($a['pages'] / max(1, $totalPages) * 100);
        $barW   = max(2, $pct);
        $color  = impact_color($a['impact']);
        $rows .= "<tr>"
               . "<td class=\"opp-title\">$title<div class=\"rule-id\">$idEsc</div></td>"
               . "<td>" . impact_badge($a['impact']) . "</td>"
               . "<td><div class=\"pgbar\"><div class=\"pgfill\" style=\"width:{$barW}%;background:$color\"></div>"
               . "<span class=\"pgtext\">{$a['pages']}/{$totalPages} ({$pct}%)</span></div></td>"
               . "<td>{$a['elements']}</td></tr>";
    }
    return <<<HTML

<div class="section-title">🏆 Top Issues (by pages affected)</div>
<div class="table-wrap" style="margin-top:10px">
  <table>
    <thead>
      <tr><th style="text-align:left">Rule</th><th>Impact</th>
          <th>Pages affected</th><th>Total elements</th></tr>
    </thead>
    <tbody>$rows</tbody>
  </table>
</div>
HTML;
}

function review_table(array $agg, int $totalPages): string {
    $review = $agg['review'];
    if (!$review) return '';
    $rows = '';
    foreach (array_slice($review, 0, 15, true) as $id => $a) {
        $help  = htmlspecialchars($a['help']);
        $url   = htmlspecialchars($a['helpUrl']);
        $idEsc = htmlspecialchars($id);
        $title = $url !== ''
            ? "<a href=\"$url\" target=\"_blank\" rel=\"noopener\">$help</a>"
            : $help;
        $pct   = round($a['pages'] / max(1, $totalPages) * 100);
        $barW  = max(2, $pct);
        $rows .= "<tr><td class=\"opp-title\">$title<div class=\"rule-id\">$idEsc</div></td>"
               . "<td><div class=\"pgbar\"><div class=\"pgfill\" style=\"width:{$barW}%;background:#64748b\"></div>"
               . "<span class=\"pgtext\">{$a['pages']}/{$totalPages} ({$pct}%)</span></div></td>"
               . "<td>{$a['elements']}</td></tr>";
    }
    return <<<HTML

<div class="section-title">🔎 Needs Manual Review (axe could not decide)</div>
<div class="table-wrap" style="margin-top:10px">
  <table>
    <thead><tr><th style="text-align:left">Check</th>
        <th>Pages affected</th><th>Total elements</th></tr></thead>
    <tbody>$rows</tbody>
  </table>
</div>
HTML;
}

function group_breakdown(array $results, array $urlToGroup): string {
    $groups = [];
    foreach ($results as $r) {
        if (empty($r['ok'])) continue;
        $g = $urlToGroup[$r['url']] ?? 'Other';
        if (!isset($groups[$g])) {
            $groups[$g] = ['pages'=>0,'violations'=>0,'critical'=>0,'serious'=>0];
        }
        $c = $r['counts'] ?? [];
        $groups[$g]['pages']++;
        $groups[$g]['violations'] += (int)($c['violations'] ?? 0);
        $groups[$g]['critical']   += (int)($c['critical'] ?? 0);
        $groups[$g]['serious']    += (int)($c['serious'] ?? 0);
    }
    if (count($groups) <= 1) return '';
    ksort($groups);

    $rows = '';
    foreach ($groups as $g => $d) {
        $gEsc = htmlspecialchars($g);
        $avg  = $d['pages'] ? round($d['violations'] / $d['pages'], 1) : 0;
        $avgColor = $avg == 0 ? '#15803d' : ($avg <= 5 ? '#b45309' : '#dc2626');
        $rows .= "<tr><td class=\"gname\">$gEsc</td><td>{$d['pages']}</td>"
               . "<td>{$d['violations']}</td>"
               . "<td>" . count_badge($d['critical'], impact_text_color('critical')) . "</td>"
               . "<td>" . count_badge($d['serious'], impact_text_color('serious')) . "</td>"
               . "<td><span style=\"color:$avgColor;font-weight:700\">$avg</span></td></tr>";
    }
    return <<<HTML

<div class="section-title">📂 By Sitemap Group</div>
<div class="table-wrap" style="margin-top:10px">
  <table>
    <thead><tr><th>Sitemap group</th><th>Pages</th><th>Total issues</th>
        <th>Critical</th><th>Serious</th><th>Avg / page</th></tr></thead>
    <tbody>$rows</tbody>
  </table>
</div>
HTML;
}

function per_page_section(array $results): string {
    $items = '';
    $rank = ['critical'=>0,'serious'=>1,'moderate'=>2,'minor'=>3];
    foreach ($results as $r) {
        $url    = $r['url'];
        $urlEsc = htmlspecialchars($url);
        $short  = htmlspecialchars(preg_replace('#^https?://#', '', $url));

        if (empty($r['ok'])) {
            $err = htmlspecialchars(mb_substr((string)($r['error'] ?? 'error'), 0, 160));
            $items .= "\n  <details class=\"opp-details\"><summary>"
                    . "<a href=\"$urlEsc\" target=\"_blank\" rel=\"noopener\">$short</a> "
                    . "<span class=\"badge-err\">load error</span></summary>"
                    . "<div class=\"opp-body\"><div class=\"err-msg\">⚠ $err</div></div></details>";
            continue;
        }

        $viol = $r['violations'] ?? [];
        if (!$viol) continue;
        usort($viol, fn($a, $b) =>
            ($rank[$a['impact']] ?? 9) <=> ($rank[$b['impact']] ?? 9)
            ?: $b['nodeCount'] <=> $a['nodeCount']);

        $lis = '';
        foreach ($viol as $v) {
            $help = htmlspecialchars($v['help']);
            $url2 = htmlspecialchars($v['helpUrl']);
            $name = $url2 !== ''
                ? "<a href=\"$url2\" target=\"_blank\" rel=\"noopener\">$help</a>" : $help;
            $cnt  = (int)($v['nodeCount'] ?? 1);
            $cntS = $cnt > 1 ? " <span class=\"opp-savings\">$cnt elements</span>" : '';
            $sample = '';
            if (!empty($v['samples'][0]['target'])) {
                $sel = htmlspecialchars(mb_substr($v['samples'][0]['target'], 0, 80));
                $sample = "<div class=\"sel\">$sel</div>";
            }
            $lis .= "<li>" . impact_badge($v['impact']) . " $name$cntS$sample</li>";
        }

        $c = $r['counts'] ?? [];
        $badges = '';
        foreach (IMPACTS as $imp) {
            $n = (int)($c[$imp] ?? 0);
            if ($n > 0) $badges .= " <span class=\"mini\" style=\"background:"
                . impact_color($imp) . "\">$n</span>";
        }

        $items .= <<<HTML

  <details class="opp-details">
    <summary><a href="$urlEsc" target="_blank" rel="noopener">$short</a>$badges</summary>
    <div class="opp-body"><ul class="opp-list">$lis</ul></div>
  </details>
HTML;
    }
    if ($items === '') return '';
    return <<<HTML

<div class="section-title">📝 Issues Per Page</div>
<div class="opp-container">$items
</div>
HTML;
}

function detail_table(array $results, array $urlToGroup): string {
    $hasGroups = count(array_unique(array_values($urlToGroup))) > 1;
    $groupHead = $hasGroups ? '<th>Group</th>' : '';
    $rows = '';
    $i = 0;
    foreach ($results as $r) {
        $i++;
        $url    = $r['url'];
        $urlEsc = htmlspecialchars($url);
        $short  = htmlspecialchars(preg_replace('#^https?://#', '', $url));
        $g      = $hasGroups
            ? '<td class="gname">' . htmlspecialchars($urlToGroup[$url] ?? '') . '</td>' : '';

        if (empty($r['ok'])) {
            $err = htmlspecialchars(mb_substr((string)($r['error'] ?? 'error'), 0, 120));
            $rows .= "<tr><td class=\"num\">$i</td>"
                   . "<td class=\"url-cell\"><a href=\"$urlEsc\" target=\"_blank\" rel=\"noopener\">$short</a></td>"
                   . "$g<td colspan=\"6\" style=\"color:#ef4444;font-size:0.72rem;text-align:left\">⚠ $err</td></tr>";
            continue;
        }
        $c = $r['counts'] ?? [];
        $cell = fn($k, $col) => '<td>' . count_badge((int)($c[$k] ?? 0), $col) . '</td>';
        $total = (int)($c['violations'] ?? 0);
        $totColor = $total === 0 ? '#15803d' : '#1e293b';
        $rows .= "<tr><td class=\"num\">$i</td>"
               . "<td class=\"url-cell\"><a href=\"$urlEsc\" target=\"_blank\" rel=\"noopener\">$short</a></td>"
               . "$g"
               . $cell('critical', impact_text_color('critical'))
               . $cell('serious',  impact_text_color('serious'))
               . $cell('moderate', impact_text_color('moderate'))
               . $cell('minor',    impact_text_color('minor'))
               . "<td style=\"color:$totColor;font-weight:700\">$total</td>"
               . "<td>" . count_badge((int)($c['incomplete'] ?? 0), '#64748b') . "</td></tr>";
    }
    return <<<HTML

<div class="section-title">📋 Full Results</div>
<div class="table-wrap">
  <table>
    <thead><tr><th>#</th><th>URL</th>$groupHead
      <th>Critical</th><th>Serious</th><th>Moderate</th><th>Minor</th>
      <th>Total</th><th>Review</th></tr></thead>
    <tbody>$rows</tbody>
  </table>
</div>
HTML;
}

function build_html(array $results, array $urlToGroup, array $agg,
                    string $sitemapUrl, string $generatedAt,
                    string $tags, ?string $engine): string {
    $totalPages = count($results);
    $cards     = summary_cards($agg, $totalPages);
    $groupHtml = group_breakdown($results, $urlToGroup);
    $topHtml   = top_issues_table($agg, $totalPages);
    $reviewHtml= review_table($agg, $totalPages);
    $perPage   = per_page_section($results);
    $detail    = detail_table($results, $urlToGroup);
    $sitemapEsc= htmlspecialchars($sitemapUrl);
    $tagsEsc   = htmlspecialchars($tags);
    $engineEsc = htmlspecialchars($engine ?? 'axe-core');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accessibility Report — $generatedAt</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body  { font-family: system-ui, -apple-system, sans-serif;
          background: #ffffff; color: #1e293b; margin: 0; padding: 24px 28px; }
  h1    { font-size: 1.6rem; margin-bottom: 4px; color: #0f172a; }
  .meta { font-size: 0.8rem; color: #475569; margin-bottom: 22px; line-height: 1.6; }
  .section-title { font-size: 0.8rem; font-weight: 700; color: #475569;
                   text-transform: uppercase; letter-spacing: .1em; margin: 32px 0 10px; }
  .cards { display: flex; flex-wrap: wrap; gap: 12px; }
  .card  { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
           padding: 16px 22px; min-width: 148px; flex: 1; }
  .card-label { font-size: 0.72rem; color: #475569; text-transform: uppercase; letter-spacing: .06em; }
  .card-score { font-size: 2.4rem; font-weight: 700; line-height: 1.1; margin: 4px 0; }
  .card-sub   { font-size: 0.7rem; color: #64748b; }
  .stats { display: flex; flex-wrap: wrap; gap: 18px; margin-top: 12px;
           font-size: 0.85rem; color: #475569; }
  .table-wrap { overflow-x: auto; border-radius: 10px; background: #ffffff;
                border: 1px solid #e2e8f0; margin-top: 4px; }
  table  { width: 100%; border-collapse: collapse; font-size: 0.77rem; color: #1e293b; }
  th, td { padding: 8px 10px; text-align: center; border-bottom: 1px solid #e2e8f0; }
  th     { background: #f1f5f9; color: #475569; font-weight: 600;
           text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; }
  td.url-cell { text-align: left; max-width: 320px; overflow: hidden;
                text-overflow: ellipsis; white-space: nowrap; }
  td.url-cell a { color: #1d4ed8; text-decoration: none; }
  td.url-cell a:hover { text-decoration: underline; }
  td.gname { text-align: left; font-size: 0.72rem; color: #475569; white-space: nowrap; }
  td.num   { color: #94a3b8; width: 32px; }
  tr:hover td { background: #f1f5f9; }
  td.opp-title { text-align: left; }
  td.opp-title a { color: #1d4ed8; text-decoration: none; }
  td.opp-title a:hover { text-decoration: underline; }
  .rule-id { font-size: 0.66rem; color: #64748b; font-family: ui-monospace, monospace; margin-top: 2px; }
  .pgbar  { position: relative; background: #f1f5f9; border: 1px solid #e2e8f0;
            border-radius: 6px; height: 18px; min-width: 160px; overflow: hidden; }
  .pgfill { height: 100%; border-radius: 6px; opacity: .85; }
  .pgtext { position: absolute; inset: 0; display: flex; align-items: center;
            justify-content: center; font-size: 0.7rem; color: #1e293b; }
  .opp-container { display: flex; flex-direction: column; gap: 6px; }
  .opp-details   { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; }
  .opp-details summary { cursor: pointer; font-size: 0.82rem; }
  .opp-details summary a { color: #1d4ed8; text-decoration: none; }
  .opp-details summary a:hover { text-decoration: underline; }
  .opp-body { margin-top: 8px; }
  .opp-list { margin: 4px 0 0; padding-left: 4px; font-size: 0.8rem; list-style: none; }
  .opp-list li { margin: 7px 0; line-height: 1.5; }
  .opp-savings { color: #b45309; font-size: 0.72rem; }
  .sel { font-family: ui-monospace, monospace; font-size: 0.68rem; color: #64748b;
         margin: 2px 0 0 6px; word-break: break-all; }
  .mini { display: inline-block; min-width: 18px; padding: 1px 6px; border-radius: 10px;
          color: #fff; font-size: 0.68rem; font-weight: 700; margin-left: 4px; }
  .badge-err { background: #b91c1c; color: #fff; padding: 1px 8px; border-radius: 10px; font-size: 0.68rem; }
  .err-msg { color: #b91c1c; font-size: 0.78rem; }
  .legend { margin-top: 22px; font-size: 0.72rem; color: #475569; }
  .dot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:4px; vertical-align:middle; }
  .disclaimer { font-size: 0.72rem; color: #64748b; margin-top: 8px; max-width: 760px; }
</style>
</head>
<body>
<h1>♿ Accessibility Bulk Report</h1>
<div class="meta">
  Sitemap: <strong>$sitemapEsc</strong> &nbsp;|&nbsp;
  Pages tested: <strong>$totalPages</strong> &nbsp;|&nbsp;
  Engine: <strong>axe-core $engineEsc</strong> &nbsp;|&nbsp;
  Standard: <strong>$tagsEsc</strong><br>
  Generated: <strong>$generatedAt</strong>
</div>

$cards
$groupHtml
$topHtml
$reviewHtml
$perPage
$detail

<div class="legend">
  <span class="dot" style="background:#ef4444"></span> Critical &nbsp;
  <span class="dot" style="background:#f97316"></span> Serious &nbsp;
  <span class="dot" style="background:#f59e0b"></span> Moderate &nbsp;
  <span class="dot" style="background:#3b82f6"></span> Minor
  <div class="disclaimer">
    ⚠ Automated checks (axe-core, WAVE, Lighthouse) reliably catch only ~30–40% of
    WCAG success criteria. A clean report is not a compliance certificate — full
    ADA / WCAG conformance also requires manual keyboard, screen-reader and
    focus-order testing. "Needs review" items in particular require a human decision.
  </div>
</div>
</body>
</html>
HTML;
}

// ─────────────────────────────────────────────────────────────────────────────
//  CSV export
// ─────────────────────────────────────────────────────────────────────────────

function build_csv(array $results, array $urlToGroup): string {
    $fields = ['url','sitemap_group','status','critical','serious','moderate','minor',
               'total_violations','needs_review','top_issues','error'];
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, $fields);
    foreach ($results as $r) {
        $url = $r['url'];
        if (empty($r['ok'])) {
            fputcsv($fh, [$url, $urlToGroup[$url] ?? '', 'error',
                          '', '', '', '', '', '', '', (string)($r['error'] ?? 'error')]);
            continue;
        }
        $c = $r['counts'] ?? [];
        $top = array_slice($r['violations'] ?? [], 0, 8);
        $topStr = implode(' | ', array_map(function ($v) {
            $n = (int)($v['nodeCount'] ?? 1);
            return $n > 1 ? "{$v['help']} ({$n})" : $v['help'];
        }, $top));
        fputcsv($fh, [
            $url, $urlToGroup[$url] ?? '', 'ok',
            (int)($c['critical'] ?? 0), (int)($c['serious'] ?? 0),
            (int)($c['moderate'] ?? 0), (int)($c['minor'] ?? 0),
            (int)($c['violations'] ?? 0), (int)($c['incomplete'] ?? 0),
            $topStr, '',
        ]);
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Console summary
// ─────────────────────────────────────────────────────────────────────────────

function print_summary(array $agg, array $results): void {
    $t = $agg['totals'];
    echo "\n─── Accessibility Summary ───────────────────────────────\n";
    printf("  Pages scanned : %d  (%d clean, %d with issues, %d errors)\n",
        $agg['okPages'] + $agg['errorPages'], $agg['cleanPages'],
        $agg['pagesWithIssues'], $agg['errorPages']);
    printf("  Violations    : %d total  —  🔴 %d critical  🟠 %d serious  🟡 %d moderate  🔵 %d minor\n",
        $t['violations'], $t['critical'], $t['serious'], $t['moderate'], $t['minor']);
    printf("  Needs review  : %d  |  Unique failing rules: %d\n",
        $t['incomplete'], $agg['uniqueRules']);

    if ($agg['rules']) {
        echo "\n  Top issues (by pages affected):\n";
        foreach (array_slice($agg['rules'], 0, 8, true) as $id => $a) {
            printf("    %3d pages  [%-8s] %s\n",
                $a['pages'], $a['impact'] ?? 'n/a', $a['help']);
        }
    }
    echo "─────────────────────────────────────────────────────────\n\n";
}

// ─────────────────────────────────────────────────────────────────────────────
//  Main
// ─────────────────────────────────────────────────────────────────────────────

function main(array $argv): void {
    $args = parse_args($argv);
    preflight($args);

    // 0 — Resolve the sitemap (accepts a direct sitemap URL or a plain site URL)
    $resolved = discover_sitemap($args['sitemap']);
    if ($resolved === null) {
        fwrite(STDERR, "❌  Could not find a sitemap for '{$args['sitemap']}'.\n"
            . "    Pass a direct sitemap URL, e.g. "
            . "--sitemap=https://example.com/sitemap_index.xml\n");
        exit(1);
    }
    $args['sitemap'] = $resolved;

    // 1 — Collect URLs from the sitemap
    [$urls, $urlToGroup] = collect_urls($args['sitemap'], $args['max-urls']);
    if (!$urls) {
        fwrite(STDERR, "❌  No page URLs found. Verify the sitemap URL is accessible.\n");
        exit(1);
    }

    // 2 — Scan with axe-core (Node runner)
    [$resultsMap, $engine] = scan_all($urls, $urlToGroup, $args);

    // Preserve sitemap order
    $results = [];
    foreach ($urls as $u) {
        if (isset($resultsMap[$u])) $results[] = $resultsMap[$u];
    }
    if (!$results) {
        fwrite(STDERR, "❌  No results returned from the scan.\n");
        exit(1);
    }

    // 3 — Aggregate
    $agg = aggregate($results);

    // 4 — HTML report
    $generatedAt = date('Y-m-d H:i');
    file_put_contents($args['output'],
        build_html($results, $urlToGroup, $agg, $args['sitemap'],
                   $generatedAt, $args['tags'], $engine));
    echo "✅  HTML report → {$args['output']}\n";

    // 5 — CSV
    file_put_contents($args['csv'], build_csv($results, $urlToGroup));
    echo "✅  CSV export  → {$args['csv']}\n";

    // 6 — Console summary
    print_summary($agg, $results);
}

// Run as a CLI tool only. When this file is require()d (e.g. by the web
// frontend in web/) we expose the functions above without auto-running.
if (PHP_SAPI === 'cli') {
    main($argv);
}
