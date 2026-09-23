<?php
/** POST {email, razorpay_order_id, razorpay_payment_id, razorpay_signature} → {ok, premiumUntil, plan} */
require __DIR__ . '/onetime-lib.php';
fg_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_json(405, ['error' => 'POST only']);
$b = fg_body();
$email = strtolower(trim($b['email'] ?? ''));
$oid = (string)($b['razorpay_order_id'] ?? ''); $pid = (string)($b['razorpay_payment_id'] ?? ''); $sig = (string)($b['razorpay_signature'] ?? '');
if (!$email || !$oid || !$pid || !$sig) fg_json(400, ['error' => 'Missing fields']);
$sec = fg_secrets(); $secret = $sec['razorpay_key_secret'] ?? '';
$want = hash_hmac('sha256', $oid . '|' . $pid, $secret);
if (!hash_equals($want, $sig)) fg_json(400, ['error' => 'Signature mismatch']);
$payment = fg_rzp_get('/payments/' . rawurlencode($pid));
if (empty($payment['id'])) fg_json(502, ['error' => 'Could not read payment']);
if (strtolower(trim($payment['notes']['email'] ?? '')) !== $email) fg_json(400, ['error' => 'Payment does not belong to this email']);
if (($payment['status'] ?? '') === 'authorized') { // auto-capture is on for this account, but be safe
    $payment = fg_rzp_get('/payments/' . rawurlencode($pid));
}
$user = fg_load_user($email) ?: [];
$new = fg_onetime_apply($user, $payment);
if (!$new && (empty($user['pass_until']) || strtotime($user['pass_until']) <= time())) fg_json(402, ['error' => 'Payment not captured yet. Open the app in a minute; it will unlock automatically.']);
fg_save_user($email, $user);
if ($new) {
    $h = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: FlashGenius <no-reply@netmock.com>\r\n";
    @mail('netmockprep@gmail.com', 'New FlashGenius ' . $user['plan'] . ' pass: ' . $email,
        '<div style="font-family:sans-serif"><h3>One-time Premium pass paid</h3><p><b>Email:</b> ' . htmlspecialchars($email) . '<br><b>Plan:</b> ' . htmlspecialchars($user['plan'])
        . '<br><b>Amount:</b> ₹' . number_format(((int)$payment['amount']) / 100) . '<br><b>Payment:</b> ' . htmlspecialchars($pid) . '<br><b>Valid till:</b> ' . date('d M Y', strtotime($user['pass_until'])) . '</p></div>', $h);
}
fg_json(200, ['ok' => true, 'premiumUntil' => $user['pass_until'], 'plan' => $user['pass_plan'] ?? ($user['plan'] ?? null)]);
