<?php
// GET → today's real news digest (PIB + News On Air), 30-min file cache.
// Response shape matches the old backend: {ok, items, content}.
require __DIR__ . '/fg-config.php';
fg_preflight();
$cacheFile = FG_DATA_DIR . '/ca_cache.json';
if (is_readable($cacheFile) && time() - filemtime($cacheFile) < 1800) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo file_get_contents($cacheFile);
    exit;
}
function fg_fetch_feed($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)']);
    $x = curl_exec($ch);
    curl_close($ch);
    if (!$x) return [];
    $items = [];
    if (preg_match_all('/<item>(.*?)<\/item>/s', $x, $m)) {
        foreach ($m[1] as $it) {
            $t = preg_match('/<title>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?<\/title>/s', $it, $tm) ? trim($tm[1]) : '';
            $d = preg_match('/<description>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?<\/description>/s', $it, $dm) ? trim(strip_tags(html_entity_decode($dm[1]))) : '';
            if ($t) $items[] = ['title' => html_entity_decode($t), 'desc' => mb_substr($d, 0, 400)];
            if (count($items) >= 15) break;
        }
    }
    return $items;
}
$all = [];
foreach ([
    'https://www.pib.gov.in/RssMain.aspx?ModId=6&Lang=1&Regid=3',
    'https://www.newsonair.gov.in/category/national/feed/',
    'https://www.newsonair.gov.in/category/international/feed/',
] as $u) { $all = array_merge($all, fg_fetch_feed($u)); }
if (!$all) { fg_json(200, ['ok' => false, 'items' => 0, 'content' => '']); }
$lines = [];
$n = 1;
foreach (array_slice($all, 0, 30) as $it) {
    $lines[] = $n . '. ' . $it['title'] . ($it['desc'] ? ' — ' . $it['desc'] : '');
    $n++;
}
$out = json_encode(['ok' => true, 'items' => count($lines),
    'content' => "REAL news items for " . date('j F Y') . " (PIB + News On Air):\n" . implode("\n", $lines)]);
if (!is_dir(FG_DATA_DIR)) mkdir(FG_DATA_DIR, 0755, true);
file_put_contents($cacheFile, $out, LOCK_EX);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
echo $out;
