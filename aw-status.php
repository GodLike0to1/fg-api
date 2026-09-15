<?php
/**
 * POST {email, session} → {aw, awUntil, today, todayDone, items:[...]}
 * items: last 60 submissions, newest first; result included only once ready.
 * If a pending item has waited more than 3 minutes without evaluation, the
 * worker is nudged for that one item (safety net if the submit-time kick failed).
 */
require __DIR__ . '/aw-lib.php';
fg_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_json(405, ['error' => 'POST only']);

$b = fg_body();
$email = strtolower(trim($b['email'] ?? ''));
if (!$email || !fg_check_session($email, $b['session'] ?? '')) fg_json(401, ['error' => 'Not signed in']);

$user = null;
$aw = aw_entitled($email, $user);
$today = aw_today();
$items = [];
$dir = aw_dir($email);
if (is_dir($dir)) {
    $metas = glob($dir . '/*.json') ?: [];
    rsort($metas);
    foreach (array_slice($metas, 0, 60) as $p) {
        $m = json_decode(file_get_contents($p), true);
        if (!is_array($m) || empty($m['date'])) continue;
        if ($m['status'] === 'pending' || ($m['status'] === 'error' && (int)($m['attempts'] ?? 0) < 3)) {
            $age = time() - (int)$m['submitted_ts'];
            $lock = $dir . '/' . $m['date'] . '.lock';
            $locked = is_file($lock) && (time() - filemtime($lock)) < 240;
            if ($age > 180 && !$locked) {
                $sec = fg_secrets(); $tok = $sec['aw_worker_token'] ?? '';
                if ($tok) {
                    $ch = curl_init('https://netmock.com/fg-api/aw-worker.php?token=' . rawurlencode($tok) . '&one=' . rawurlencode(sha1($email) . '/' . $m['date']));
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_NOSIGNAL => 1]);
                    @curl_exec($ch); curl_close($ch);
                }
            }
        }
        $items[] = aw_public($m);
    }
}
$rules = aw_rules();
fg_json(200, [
    'aw' => $aw,
    'awUntil' => $user['aw_until'] ?? null,
    'today' => $today,
    'todayDone' => (bool)aw_load($email, $today),
    'wordsMin' => $rules['words_min'], 'wordsMax' => $rules['words_max'], 'maxMarks' => $rules['max_marks'],
    'readyAfterMinutes' => $rules['ready_after_minutes'],
    'items' => $items,
]);
