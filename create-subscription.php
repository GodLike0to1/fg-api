<?php
/**
 * POST {email} → ensures the ₹199/month plan exists (auto-creates via API),
 * creates a Razorpay subscription, returns {subscriptionId, keyId} for
 * Razorpay Checkout on the WEB page. Never called from inside the app.
 */
require __DIR__ . '/fg-config.php';
fg_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_json(405, ['error' => 'POST only']);

$b = fg_body();
$email = strtolower(trim($b['email'] ?? ''));
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) fg_json(400, ['error' => 'Valid email required']);
$isAw = (($b['plan'] ?? '') === 'aw');

$sec = fg_secrets();
$key = $sec['razorpay_key_id'] ?? '';
$secret = $sec['razorpay_key_secret'] ?? '';
if (!$key || !$secret) fg_json(500, ['error' => 'Server not configured']);

function rzp($method, $path, $payload, $key, $secret) {
    $ch = curl_init('https://api.razorpay.com/v1' . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $key . ':' . $secret,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ];
    if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = json_encode($payload); }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$resp, true)];
}

// Find or create the ₹199/month plan. Cached in a local file after first run.
$planFile = __DIR__ . ($isAw ? '/data/plan_aw_id.txt' : '/data/plan_id.txt');
$planId = is_readable($planFile) ? trim(file_get_contents($planFile)) : '';
if (!$planId) {
    [$c, $list] = rzp('GET', '/plans?count=100', null, $key, $secret);
    if ($c === 200 && !empty($list['items'])) {
        foreach ($list['items'] as $p) {
            if (($p['period'] ?? '') === 'monthly' && (int)($p['item']['amount'] ?? 0) === ($isAw ? 99900 : 19900)) { $planId = $p['id']; break; }
        }
    }
    if (!$planId) {
        [$c2, $p] = rzp('POST', '/plans', [
            'period' => 'monthly', 'interval' => 1,
            'item' => $isAw
                ? ['name' => 'FlashGenius Answer Writing + Premium — Monthly', 'amount' => 99900, 'currency' => 'INR',
                   'description' => 'Daily answer evaluation (1 answer/day, marks out of 10) + all MCQ chapters']
                : ['name' => 'FlashGenius Premium — Monthly', 'amount' => 19900, 'currency' => 'INR',
                   'description' => 'Unlimited MCQs, all chapters, UPSC/UPPCS/NCERT'],
        ], $key, $secret);
        if ($c2 < 200 || $c2 >= 300 || empty($p['id'])) {
            fg_json(502, ['error' => $p['error']['description'] ?? 'Could not create plan']);
        }
        $planId = $p['id'];
    }
    if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0755, true);
    file_put_contents($planFile, $planId);
}

[$c3, $j] = rzp('POST', '/subscriptions', [
    'plan_id' => $planId,
    'total_count' => 120,
    'customer_notify' => 1,
    'notes' => ['email' => $email, 'product' => $isAw ? 'flashgenius_aw' : 'flashgenius_premium'],
], $key, $secret);
if ($c3 < 200 || $c3 >= 300 || empty($j['id'])) {
    fg_json(502, ['error' => $j['error']['description'] ?? 'Could not create subscription']);
}

$user = fg_load_user($email) ?: [];
$user['pending_subscription'] = $j['id'];
fg_save_user($email, $user);

fg_json(200, ['subscriptionId' => $j['id'], 'keyId' => $key]);
