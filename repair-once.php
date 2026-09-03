<?php
// One-shot repair: restore paid premium for a specific student, using the
// server's own Razorpay keys. Hardcoded email, grants only what Razorpay
// confirms was paid. Removed from the repo right after it is run.
require __DIR__ . '/fg-config.php';
header('Content-Type: application/json; charset=utf-8');
$email = 'marothiagarima100@gmail.com';
$user = fg_load_user($email) ?: [];
$before = $user;
$r = fg_resolve_paid_access($email, $user);
if ($r['grant']) {
    $user['premium_until'] = date('c', $r['end'] + 24 * 3600);
    $user['subscription_id'] = $r['sub_id'];
    $user['source'] = 'razorpay_web';
    if (($r['sub']['status'] ?? '') === 'cancelled') $user['cancelled'] = $user['cancelled'] ?? date('c');
    unset($user['pending_subscription']);
    fg_save_user($email, $user);
}
$s = $r['sub'] ?? [];
echo json_encode([
    'email' => $email,
    'record_before' => ['premium_until' => $before['premium_until'] ?? null, 'subscription_id' => $before['subscription_id'] ?? null, 'pending_subscription' => $before['pending_subscription'] ?? null, 'exists' => !empty($before)],
    'razorpay' => $s ? ['id' => $s['id'] ?? null, 'status' => $s['status'] ?? null, 'paid_count' => $s['paid_count'] ?? null, 'notes_email' => $s['notes']['email'] ?? null,
        'current_start' => !empty($s['current_start']) ? date('c', (int)$s['current_start']) : null, 'current_end' => !empty($s['current_end']) ? date('c', (int)$s['current_end']) : null, 'ended_at' => !empty($s['ended_at']) ? date('c', (int)$s['ended_at']) : null] : null,
    'why' => $r['why'],
    'granted' => $r['grant'],
    'premium_until_now' => $user['premium_until'] ?? null,
], JSON_PRETTY_PRINT);
