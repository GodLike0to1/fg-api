<?php
/**
 * Razorpay webhook — set URL https://netmock.com/fg-api/razorpay-webhook.php
 * in the Razorpay dashboard for events: subscription.charged, subscription.activated,
 * subscription.cancelled. Extends premium_until by 31 days on every charge.
 */
require __DIR__ . '/fg-config.php';

$raw = file_get_contents('php://input');
$sec = fg_secrets();
$whsec = $sec['razorpay_webhook_secret'] ?? '';
$sig = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
if (!$whsec || !$sig || !hash_equals(hash_hmac('sha256', $raw, $whsec), $sig)) {
    http_response_code(400); echo 'bad signature'; exit;
}

$e = json_decode($raw, true);
$event = $e['event'] ?? '';
$sub = $e['payload']['subscription']['entity'] ?? null;
$email = strtolower(trim($sub['notes']['email'] ?? ''));
if (!$email) { http_response_code(200); echo 'no email, ignored'; exit; }

$user = fg_load_user($email) ?: [];

if ($event === 'subscription.charged' || $event === 'subscription.activated') {
    // Paid through = end of the cycle this charge paid for (+1 day grace). Setting a date
    // (not adding 31 days) keeps repeated or late webhooks from stacking free months.
    $cur = isset($user['premium_until']) ? strtotime($user['premium_until']) : 0;
    $through = fg_sub_paid_through($sub);
    if (!$through) $through = time() + 31 * 24 * 3600;
    $user['premium_until'] = date('c', max($cur, $through + 24 * 3600));
    $user['source'] = 'razorpay_web';
    $user['subscription_id'] = $sub['id'] ?? ($user['subscription_id'] ?? null);
    unset($user['pending_subscription']);
    fg_save_user($email, $user);
} elseif ($event === 'subscription.cancelled') {
    $user['cancelled'] = date('c');                 // premium runs to expiry, no renewal
    // If the student already paid for the current period, make sure the paid
    // access survives the cancellation (cancel-after-first-charge case).
    $paid = (int)($sub['paid_count'] ?? 0);
    $end = !empty($sub['current_end']) ? (int)$sub['current_end'] : 0;
    if ($paid >= 1 && $end > time()) {
        $cur = isset($user['premium_until']) ? strtotime($user['premium_until']) : 0;
        if ($end + 24 * 3600 > $cur) $user['premium_until'] = date('c', $end + 24 * 3600); // 1-day grace
        $user['subscription_id'] = $sub['id'] ?? ($user['subscription_id'] ?? null);
        unset($user['pending_subscription']);
    }
    fg_save_user($email, $user);
}

http_response_code(200);
echo 'ok';
