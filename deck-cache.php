<?php
// Shared MCQ deck pool. GET ?k=<key>&seen=id1,id2 → {cards, id} | {}.
// POST {k, cards} → stores a generated deck for other students of the same
// chapter (saves Gemini calls). Flat JSON files under data/pool/.
require __DIR__ . '/fg-config.php';
fg_preflight();
$dir = FG_DATA_DIR . '/pool';
if (!is_dir($dir)) { mkdir($dir, 0755, true); @file_put_contents(FG_DATA_DIR . '/.htaccess', "Require all denied\n"); }
$key = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['k'] ?? (fg_body()['k'] ?? ''));
if (!$key) fg_json(400, ['error' => 'k required']);
$file = $dir . '/' . $key . '.json';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pool = is_readable($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    if (!$pool) fg_json(200, (object)[]);
    $seen = array_filter(explode(',', $_GET['seen'] ?? ''));
    foreach ($pool as $entry) {
        if (!in_array($entry['id'], $seen, true)) fg_json(200, ['cards' => $entry['cards'], 'id' => $entry['id']]);
    }
    fg_json(200, (object)[]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = fg_body();
    if (empty($b['cards']) || !is_array($b['cards'])) fg_json(400, ['error' => 'cards required']);
    $pool = is_readable($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    $pool[] = ['id' => substr(sha1(json_encode($b['cards'])), 0, 12), 'cards' => $b['cards'], 'ts' => time()];
    if (count($pool) > 20) $pool = array_slice($pool, -20);
    file_put_contents($file, json_encode($pool), LOCK_EX);
    fg_json(200, ['ok' => true]);
}
fg_json(405, ['error' => 'GET or POST']);
