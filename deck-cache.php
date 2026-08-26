<?php
// Shared MCQ deck pool — serves PRE-GENERATED decks from the flashgenius-data
// GitHub repo (written by tools/warm-pools). Protocol the app expects:
//   GET ?k=<key>&seen=v1,v2  →  {found:true, v, cards, total}
//                             | {found:false, total, exhausted:true}   (all seen)
//                             | {found:false, total:0}                 (no pool)
// POST {k, cards} → stores a runtime-generated deck locally (legacy, harmless).
// GitHub responses are cached on disk for 15 min so students don't wait.
require __DIR__ . '/fg-config.php';
fg_preflight();
$GH = 'https://raw.githubusercontent.com/GodLike0to1/flashgenius-data/refs/heads/main/decks/';
$dir = FG_DATA_DIR . '/ghpool2';
if (!is_dir($dir)) { mkdir($dir, 0755, true); @file_put_contents(FG_DATA_DIR . '/.htaccess', "Require all denied\n"); }

function dc_fetch($GH, $dir, $name, $ttl) {
    $f = $dir . '/' . preg_replace('/[^a-zA-Z0-9_.\-]/', '', $name);
    if (is_readable($f) && (time() - filemtime($f)) < $ttl) {
        $s = file_get_contents($f);
        if ($s === 'MISS') return null;
        return $s;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $s = @file_get_contents($GH . $name, false, $ctx);
    $code = 0;
    if (isset($http_response_header)) foreach ($http_response_header as $h)
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
    if ($code === 200 && $s !== false) { file_put_contents($f, $s, LOCK_EX); return $s; }
    if ($code === 404) { file_put_contents($f, 'MISS', LOCK_EX); return null; }
    // network trouble → serve stale cache if any
    if (is_readable($f)) { $s = file_get_contents($f); return $s === 'MISS' ? null : $s; }
    return false; // unknown failure, no cache
}

$key = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['k'] ?? (fg_body()['k'] ?? ''));
if (!$key) fg_json(400, ['error' => 'k required']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $idxRaw = dc_fetch($GH, $dir, $key . '-idx.json', 900);
    $n = 0;
    if ($idxRaw) { $idx = json_decode($idxRaw, true); $n = (int)($idx['n'] ?? 0); }
    elseif ($idxRaw === null) {
        // no idx — maybe a single un-indexed deck
        $one = dc_fetch($GH, $dir, $key . '.json', 900);
        if ($one) { $cards = json_decode($one, true);
            if (is_array($cards) && count($cards)) $n = 1; }
    }
    if ($n === 0) fg_json(200, ['found' => false, 'total' => 0]);
    $seen = [];
    foreach (array_filter(explode(',', $_GET['seen'] ?? '')) as $s) $seen[(int)$s] = true;
    for ($v = 1; $v <= $n; $v++) {
        if (isset($seen[$v])) continue;
        $raw = dc_fetch($GH, $dir, $key . ($v > 1 ? "-$v" : '') . '.json', 21600);
        if (!$raw) continue; // gap or fetch problem — try next variant
        $cards = json_decode($raw, true);
        if (is_array($cards) && count($cards))
            fg_json(200, ['found' => true, 'v' => $v, 'cards' => $cards, 'total' => $n]);
    }
    // All variants seen → RECYCLE from the start instead of reporting exhausted,
    // so the app (any version) never falls back to a live AI call.
    $v = (count($seen) % $n) + 1;
    for ($t = 0; $t < $n; $t++) {
        $vv = (($v - 1 + $t) % $n) + 1;
        $raw = dc_fetch($GH, $dir, $key . ($vv > 1 ? "-$vv" : '') . '.json', 86400);
        if (!$raw) continue;
        $cards = json_decode($raw, true);
        if (is_array($cards) && count($cards))
            fg_json(200, ['found' => true, 'v' => $vv, 'cards' => $cards, 'total' => $n]);
    }
    fg_json(200, ['found' => false, 'total' => 0]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = fg_body();
    if (empty($b['cards']) || !is_array($b['cards'])) fg_json(400, ['error' => 'cards required']);
    $legacy = FG_DATA_DIR . '/pool';
    if (!is_dir($legacy)) mkdir($legacy, 0755, true);
    $file = $legacy . '/' . $key . '.json';
    $pool = is_readable($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    $pool[] = ['id' => substr(sha1(json_encode($b['cards'])), 0, 12), 'cards' => $b['cards'], 'ts' => time()];
    if (count($pool) > 20) $pool = array_slice($pool, -20);
    file_put_contents($file, json_encode($pool), LOCK_EX);
    fg_json(200, ['ok' => true]);
}
fg_json(405, ['error' => 'GET or POST']);
