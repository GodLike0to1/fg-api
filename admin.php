<?php
/**
 * Owner admin tool — inspect a student and (re)grant paid premium.
 * Secret-gated by session_hmac_key (already in fg-secret.php). Never in the app.
 *
 *   GET /fg-api/admin.php?k=<session_hmac_key>&action=inspect&email=<email>
 *   GET /fg-api/admin.php?k=<session_hmac_key>&action=grant&email=<email>&days=31
 *
 * "inspect" shows the stored user record AND the live Razorpay subscription
 * (status, paid_count, current_start/end, ended_at) so we can see why premium
 * is or isn't unlocking. "grant" sets premium_until (default: Razorpay's paid
 * period end if known, else +days) — use to restore a paying student instantly.
 */
require __DIR__ . '/fg-config.php';
header('Content-Type: application/json; charset=utf-8');

$sec = fg_secrets();
$k = $_GET['k'] ?? '';
if (!$k || !hash_equals((string)($sec['session_hmac_key'] ?? 'dev'), (string)$k)) {
    http_response_code(403); echo json_encode(['error' => 'forbidden']); exit;
}

$email  = strtolower(trim($_GET['email'] ?? ''));
$action = $_GET['action'] ?? 'inspect';
$days   = max(1, (int)($_GET['days'] ?? 31));
if (!$email) { http_response_code(400); echo json_encode(['error' => 'email required']); exit; }

$user = fg_load_user($email) ?: [];

// Pull the live subscription from Razorpay if we have an id on file.
$subId = $user['subscription_id'] ?? ($user['pending_subscription'] ?? null);
$sub = null;
if ($subId) {
    $key = $sec['razorpay_key_id'] ?? ''; $secret = $sec['razorpay_key_secret'] ?? '';
    if ($key && $secret) {
        $ch = curl_init('https://api.razorpay.com/v1/subscriptions/' . rawurlencode($subId));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $key.':'.$secret, CURLOPT_TIMEOUT => 20]);
        $sub = json_decode((string)curl_exec($ch), true); curl_close($ch);
    }
}

if ($action === 'inspect') {
    echo json_encode([
        'email' => $email,
        'record' => $user,
        'subscription_id' => $subId,
        'razorpay_subscription' => $sub ? [
            'id' => $sub['id'] ?? null,
            'status' => $sub['status'] ?? null,
            'paid_count' => $sub['paid_count'] ?? null,
            'current_start' => isset($sub['current_start']) ? date('c', (int)$sub['current_start']) : null,
            'current_end' => isset($sub['current_end']) ? date('c', (int)$sub['current_end']) : null,
            'ended_at' => isset($sub['ended_at']) ? date('c', (int)$sub['ended_at']) : null,
            'charge_at' => isset($sub['charge_at']) ? date('c', (int)$sub['charge_at']) : null,
        ] : null,
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'grant') {
    // Prefer Razorpay's real paid-period end; fall back to +days from now.
    $end = 0;
    if ($sub) {
        if (!empty($sub['current_end'])) $end = (int)$sub['current_end'];
        elseif (!empty($sub['ended_at'])) $end = (int)$sub['ended_at'] + $days * 24 * 3600;
    }
    if ($end < time()) $end = time() + $days * 24 * 3600;
    $user['premium_until'] = date('c', $end + 24 * 3600); // 1-day grace
    $user['source'] = 'manual_admin';
    if ($subId) $user['subscription_id'] = $subId;
    unset($user['pending_subscription']);
    fg_save_user($email, $user);
    echo json_encode(['ok' => true, 'email' => $email, 'premium_until' => $user['premium_until']], JSON_PRETTY_PRINT);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action']);
