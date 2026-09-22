<?php
/**
 * One-time Premium passes (no mandate): 3 months ₹799, 12 months ₹1,999.
 * Paid through a Razorpay ORDER (premium.html?plan=q|y), verified by
 * verify-payment.php (signature) and, as a safety net, re-discovered by
 * entitlement.php from the payments list (notes.email + notes.product).
 * Fields on the fg user record: premium_until, plan ('quarter'|'annual'),
 * onetime_payments: [payment_id, ...]
 */
require_once __DIR__ . '/fg-config.php';
const FG_ONETIME = [
    'q' => ['amount' => 79900,  'days' => 92,  'plan' => 'quarter', 'product' => 'flashgenius_q', 'name' => 'FlashGenius Premium — 3 months'],
    'y' => ['amount' => 199900, 'days' => 366, 'plan' => 'annual',  'product' => 'flashgenius_y', 'name' => 'FlashGenius Premium — 12 months (till Prelims)'],
];
function fg_rzp_get($path) {
    $sec = fg_secrets(); $key = $sec['razorpay_key_id'] ?? ''; $secret = $sec['razorpay_key_secret'] ?? '';
    if (!$key || !$secret) return [];
    $ch = curl_init('https://api.razorpay.com/v1' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $key . ':' . $secret, CURLOPT_TIMEOUT => 20]);
    $r = json_decode((string)curl_exec($ch), true); curl_close($ch);
    return is_array($r) ? $r : [];
}
function fg_onetime_code_for_product($product) { foreach (FG_ONETIME as $c => $p) if ($p['product'] === $product) return $c; return null; }
// Apply a captured one-time payment to the user record (idempotent). Returns true if newly applied.
function fg_onetime_apply(&$user, $payment) {
    $pid = $payment['id'] ?? ''; if (!$pid) return false;
    $done = $user['onetime_payments'] ?? [];
    if (in_array($pid, $done, true)) return false;
    $code = fg_onetime_code_for_product($payment['notes']['product'] ?? '');
    if (!$code || ($payment['status'] ?? '') !== 'captured') return false;
    if ((int)($payment['amount'] ?? 0) < FG_ONETIME[$code]['amount']) return false;
    $days = FG_ONETIME[$code]['days'];
    $start = max(time(), !empty($user['premium_until']) ? (int)strtotime($user['premium_until']) : 0);
    // A pass bought while an older window still runs starts when that window ends.
    $paidAt = (int)($payment['created_at'] ?? time());
    if ($start === time() && $paidAt < time()) $start = $paidAt; // back-date if discovered late
    $user['premium_until'] = date('c', $start + $days * 86400);
    $user['plan'] = FG_ONETIME[$code]['plan'];
    $user['source'] = 'razorpay_onetime';
    $done[] = $pid; $user['onetime_payments'] = array_slice($done, -20);
    unset($user['pending_order']);
    return true;
}
// Safety net: find captured one-time payments for this email that were never applied.
function fg_onetime_resolve($email, &$user) {
    $list = fg_rzp_get('/payments?count=100');
    $applied = false;
    foreach (($list['items'] ?? []) as $p) {
        if (strtolower(trim($p['notes']['email'] ?? '')) !== $email) continue;
        if (fg_onetime_apply($user, $p)) $applied = true;
    }
    return $applied;
}
