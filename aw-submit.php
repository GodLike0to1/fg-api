<?php
/**
 * POST {email, session, question, custom, files:[{type, base64, name}]}
 * One submission per student per IST day. Files: JPEG/PNG/PDF, max 6, 12 MB total.
 * Stores the upload, marks it pending, kicks the evaluator in the background.
 * → {ok, date, readyAt}
 */
require __DIR__ . '/aw-lib.php';
fg_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_json(405, ['error' => 'POST only']);

$b = fg_body();
$email = strtolower(trim($b['email'] ?? ''));
if (!$email || !fg_check_session($email, $b['session'] ?? '')) fg_json(401, ['error' => 'Not signed in']);

$user = null;
if (!aw_entitled($email, $user)) fg_json(402, ['error' => 'Answer Writing needs the ₹999/month plan.', 'code' => 'aw_required']);

$rules = aw_rules();
$today = aw_today();
if (aw_load($email, $today)) fg_json(409, ['error' => 'You have already submitted today\'s answer. Next submission opens tomorrow.', 'code' => 'daily_limit']);

$question = trim((string)($b['question'] ?? ''));
if (mb_strlen($question) < 10) fg_json(400, ['error' => 'Question is missing']);
$question = mb_substr($question, 0, 1200);

$files = $b['files'] ?? [];
if (!is_array($files) || !count($files)) fg_json(400, ['error' => 'Upload your answer (photo or PDF)']);
if (count($files) > 6) fg_json(400, ['error' => 'Maximum 6 pages per answer']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
$total = 0; $saved = [];
$dir = aw_dir($email);
if (!is_dir($dir)) mkdir($dir, 0755, true);
foreach ($files as $i => $f) {
    $type = strtolower((string)($f['type'] ?? ''));
    if (!isset($allowed[$type])) fg_json(400, ['error' => 'Only JPG, PNG or PDF files are accepted']);
    $bin = base64_decode((string)($f['base64'] ?? ''), true);
    if ($bin === false || strlen($bin) < 100) fg_json(400, ['error' => 'A file could not be read']);
    $total += strlen($bin);
    if ($total > 12 * 1024 * 1024) fg_json(400, ['error' => 'Upload is too large (12 MB max). Use smaller photos.']);
    $name = $today . '-' . ($i + 1) . '.' . $allowed[$type];
    file_put_contents($dir . '/' . $name, $bin, LOCK_EX);
    $saved[] = ['file' => $name, 'type' => $type, 'bytes' => strlen($bin)];
}

$meta = [
    'date' => $today,
    'email' => $email,
    'question' => $question,
    'custom' => !empty($b['custom']),
    'gs' => (string)($b['gs'] ?? ''),
    'files' => $saved,
    'status' => 'pending',
    'attempts' => 0,
    'submitted_ts' => time(),
    'submitted_at' => date('c'),
];
aw_save($email, $today, $meta);

// Fire-and-forget: ask the worker to evaluate now (result is still released
// only at readyAt). aw-status.php also nudges the worker, so a missed kick is fine.
$sec = fg_secrets();
$tok = $sec['aw_worker_token'] ?? '';
if ($tok) {
    $ch = curl_init('https://netmock.com/fg-api/aw-worker.php?token=' . rawurlencode($tok) . '&one=' . rawurlencode(sha1($email) . '/' . $today));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_NOSIGNAL => 1]);
    @curl_exec($ch); curl_close($ch);
}

fg_json(200, ['ok' => true, 'date' => $today, 'readyAt' => date('c', time() + 60 * (int)$rules['ready_after_minutes'])]);
