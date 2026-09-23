<?php
/**
 * POST {email, session} → {premium, premiumUntil, aw, awUntil, plan, trial, trialUntil,
 *                          source, subscriptionId, verified, name}
 * Called by the app on every launch and while a payment is being completed.
 * Premium = time actually paid for (entitlement-lib.php). No grace days: a renewal
 * that has not been debited, or has failed, gives no Premium.
 * verified=false means Razorpay could not be read right now; the app then keeps
 * what it has instead of locking a paying student out.
 */
require __DIR__ . '/entitlement-lib.php';
fg_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_json(405, ['error' => 'POST only']);

$b = fg_body();
$email = strtolower(trim($b['email'] ?? ''));
if (!$email || !fg_check_session($email, $b['session'] ?? '')) {
    fg_json(401, ['error' => 'Not signed in']);
}

$user = fg_load_user($email) ?: [];
$e = fg_entitlement($email, $user);

// First paid activation → alert the owner: a new subscriber paid.
if (!empty($e['_newSub'])) {
    $user['owner_notified'] = true;
    $sec = fg_secrets();
    $key = $sec['razorpay_key_id'] ?? ''; $secret = $sec['razorpay_key_secret'] ?? '';
    $cname = ''; $cphone = '';
    if (!empty($user['sub_customer']) && $key && $secret) {
        $ch2 = curl_init('https://api.razorpay.com/v1/customers/' . rawurlencode($user['sub_customer']));
        curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $key.':'.$secret, CURLOPT_TIMEOUT => 15]);
        $cr = json_decode((string)curl_exec($ch2), true); curl_close($ch2);
        $cname = $cr['name'] ?? ''; $cphone = $cr['contact'] ?? '';
    }
    $who = $cname !== '' ? $cname : $email;
    $h = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: FlashGenius <no-reply@netmock.com>\r\n";
    @mail('netmockprep@gmail.com',
        'New FlashGenius subscriber: ' . $who,
        '<div style="font-family:sans-serif"><h3>New ₹199/month subscriber 🎉</h3>'
        . '<p><b>Name:</b> ' . htmlspecialchars($cname !== '' ? $cname : '(not provided)') . '<br>'
        . '<b>Email:</b> ' . htmlspecialchars($email) . '<br>'
        . '<b>Phone:</b> ' . htmlspecialchars($cphone !== '' ? $cphone : '(not provided)') . '<br>'
        . '<b>Subscription:</b> ' . htmlspecialchars((string)($user['subscription_id'] ?? '')) . '<br>'
        . '<b>Status:</b> ' . htmlspecialchars((string)($user['sub_status'] ?? '')) . '<br>'
        . '<b>Paid till:</b> ' . date('d M Y', strtotime($user['sub_until'])) . '</p></div>', $h);
}
fg_save_user($email, $user);
unset($e['_newSub']);
$e['name'] = $user['name'] ?? null;
fg_json(200, $e);
