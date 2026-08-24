<?php
/**
 * FlashGenius — server-side Gemini proxy (Hostinger / netmock.com)
 *
 * WHY THIS EXISTS
 * The Android app used to call generativelanguage.googleapis.com directly with
 * the API key baked into its JavaScript bundle. An APK is just a ZIP, so anyone
 * could unzip it, read the key, and spend money on our Google Cloud account.
 *
 * Now the key lives ONLY on this server, in fg-secret.php (which is never
 * shipped to any phone). The app posts here; we forward to Google.
 *
 * Upload to:  public_html/flashgenius-api/gemini.php
 * Endpoint:   https://netmock.com/flashgenius-api/gemini.php
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

// ---- API key -------------------------------------------------------------
// fg-secret.php sits next to this file and does:  <?php return 'AIza...';
$secretFile = __DIR__ . '/fg-secret.php';
$API_KEY = is_readable($secretFile) ? include $secretFile : getenv('GEMINI_API_KEY');
if (!$API_KEY) {
    http_response_code(500);
    echo json_encode(['error' => 'Server is not configured yet.']);
    exit;
}

// ---- Simple rate limit ---------------------------------------------------
// Blunts a scraper hammering this endpoint from one IP. Not airtight, but it
// turns "unlimited free API" into "not worth stealing".
$ip = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')[0]
    ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$ip = trim($ip);

$WINDOW = 60;      // seconds
$MAX    = 30;      // requests per window per IP
$bucket = sys_get_temp_dir() . '/fg_rl_' . md5($ip);
$now    = time();
$state  = ['start' => $now, 'n' => 0];
if (is_readable($bucket)) {
    $prev = @json_decode(@file_get_contents($bucket), true);
    if (is_array($prev) && isset($prev['start']) && ($now - $prev['start']) < $WINDOW) {
        $state = $prev;
    }
}
$state['n']++;
@file_put_contents($bucket, json_encode($state), LOCK_EX);
if ($state['n'] > $MAX) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests - please wait a minute and try again.']);
    exit;
}

// ---- Request body --------------------------------------------------------
$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);
$body    = (is_array($payload) && isset($payload['body'])) ? $payload['body'] : null;

if (!$body || !isset($body['contents'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing request body.']);
    exit;
}

// Only allow models from our list — never let the client name an arbitrary
// model or path.
$MODELS = ['gemini-2.5-flash-lite', 'gemini-2.5-flash', 'gemini-2.0-flash'];
$wanted = $payload['model'] ?? null;
$order  = ($wanted && in_array($wanted, $MODELS, true))
    ? array_merge([$wanted], array_values(array_diff($MODELS, [$wanted])))
    : $MODELS;

// ---- Forward to Google, falling back across models on quota errors -------
$lastErr = '';
foreach ($order as $model) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . rawurlencode($model) . ':generateContent?key=' . rawurlencode($API_KEY);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr   = curl_error($ch);
    curl_close($ch);

    if ($resp !== false && $status >= 200 && $status < 300) {
        http_response_code(200);
        echo $resp;                 // pass Google's JSON straight through
        exit;
    }

    if ($status === 429) { $lastErr = 'quota'; continue; }   // try next model

    if ($cerr) {
        $lastErr = $cerr;
    } else {
        $j = json_decode((string)$resp, true);
        $lastErr = $j['error']['message'] ?? ('API error ' . $status);
    }
    break;  // a non-quota error will repeat on the other models too
}

http_response_code(503);
echo json_encode([
    'error' => $lastErr === 'quota'
        ? 'Too many students are generating questions right now - please try again in a few minutes.'
        : ($lastErr ?: 'Could not generate questions right now.')
]);
