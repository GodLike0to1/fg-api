<?php
/**
 * Razorpay webhook — URL https://netmock.com/fg-api/razorpay-webhook.php
 * Events: subscription.charged, subscription.activated, subscription.cancelled,
 * subscription.pending, subscription.halted, subscription.completed.
 * The webhook never adds days itself: it just makes the server re-read from
 * Razorpay what has actually been PAID (entitlement-lib.php), so a repeated,
 * late or failed-renewal event can never create free Premium.
 */
require __DIR__ . '/entitlement-lib.php';

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
if (!$email || strpos($event, 'subscription.') !== 0) { http_response_code(200); echo 'ignored'; exit; }

$user = fg_load_user($email) ?: [];
if (!empty($sub['id']) && empty($user['subscription_id']) && (int)($sub['paid_count'] ?? 0) > 0) $user['subscription_id'] = $sub['id'];
if ($event === 'subscription.cancelled' && empty($user['cancelled'])) $user['cancelled'] = date('c'); // paid month runs out, no renewal
$user['sub_checked'] = null;                  // force a fresh read of what is actually paid
fg_entitlement($email, $user);
fg_save_user($email, $user);

http_response_code(200);
echo 'ok';
