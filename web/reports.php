<?php
/**
 * Read-only list of saved run snapshots, newest first, for the "compare
 * against a previous run" dropdown in the frontend. Reads the per-run
 * snapshot JSON files written by scan.php into ./reports/{id}.json.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dir = __DIR__ . '/reports';
$out = [];

foreach (glob($dir . '/*.json') ?: [] as $path) {
    $id = basename($path, '.json');
    // Skip the stop-scan status sidecars (they aren't snapshots).
    if (substr($id, -7) === '.status') continue;
    $s = json_decode((string)@file_get_contents($path), true);
    if (!is_array($s) || !isset($s['rules'])) continue;   // not a snapshot
    $out[] = [
        'id'          => $id,
        'generatedAt' => (string)($s['generatedAt'] ?? ''),
        'sitemap'     => (string)($s['sitemap'] ?? ''),
        'pages'       => (int)($s['pages'] ?? 0),
        'code'        => (int)($s['categories']['code']['violations'] ?? 0),
        'design'      => (int)($s['categories']['design']['violations'] ?? 0),
        'mtime'       => (int)@filemtime($path),
    ];
}

// Newest first (by file mtime — robust regardless of the generatedAt format).
usort($out, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
foreach ($out as &$r) unset($r['mtime']);

echo json_encode($out);
