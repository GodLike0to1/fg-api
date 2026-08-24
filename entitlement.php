<?php
/**
 * POST {email, session} → {premium, premiumUntil, source}.
 * Called by the app on every launch. Webhook-independent: if local premium is
 * missing/expired but a subscription exists, we ask Razorpay directly and
 * extend. So the first payment AND renewals both work even before any webhook.
 */
require __DIR__ . '/fg-config.php';
fg_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_json(405, ['error' => 'POST only']);

$b = fg_body();
$email = strtolower(trim($b['email'] ?? ''));
if (!$email || !fg_check_session($email, $b['session'] ?? '')) {
    fg_json(401, ['error' => 'Not signed in']);
}

$user = fg_load_user($email) ?: [];
$until = $user['premium_until'] ?? null;
$premium = $until && strtotime($until) > time();

// Not premium locally? Check Razorpay for a live subscription on this email.
if (!$premium) {
    $subId = $user['subscription_id'] ?? ($user['pending_subscription'] ?? null);
    if ($subId) {
        $sec = fg_secrets();
        $key = $sec['razorpay_key_id'] ?? ''; $secret = $sec['razorpay_key_secret'] ?? '';
        if ($key && $secret) {
            $ch = curl_init('https://api.razorpay.com/v1/subscriptions/' . rawurlencode($subId));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $key.':'.$secret, CURLOPT_TIMEOUT => 20]);
            $resp = curl_exec($ch); curl_close($ch);
            $s = json_decode((string)$resp, true);
            $status = $s['status'] ?? '';
            if (in_array($status, ['active', 'authenticated'], true)) {
                // current_end = end of the paid period (epoch); fall back to +31d.
                $end = !empty($s['current_end']) ? (int)$s['current_end'] : (time() + 31 * 24 * 3600);
                if ($end < time()) $end = time() + 31 * 24 * 3600; // authenticated but not yet charged
                $user['premium_until'] = date('c', $end + 24 * 3600); // 1-day grace
                $user['subscription_id'] = $subId;
                $user['source'] = 'razorpay_web';
                unset($user['pending_subscription']);
                fg_save_user($email, $user);
                $until = $user['premium_until'];
                $premium = true;
            }
        }
    }
}

fg_json(200, [
    'premium' => $premium,
    'premiumUntil' => $until,
    'source' => $user['source'] ?? null,
]);
