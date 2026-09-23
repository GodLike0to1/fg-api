<?php
/**
 * POST {email, session} → {premium, premiumUntil, source}.
 * Called by the app on every launch. Webhook-independent: if local premium is
 * missing/expired, we ask Razorpay directly (fg_resolve_paid_access) — this
 * covers first payment, renewals, AND cancelled-after-paying (access runs to
 * the end of the paid period), and it finds the subscription by email even if
 * the local record lost the id.
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

// One-time passes (3 / 12 months): apply any captured payment not yet applied.
require_once __DIR__ . '/onetime-lib.php';
if (!$premium) {
    if (fg_onetime_resolve($email, $user)) { fg_save_user($email, $user); $until = $user['premium_until']; $premium = strtotime($until) > time(); }
}
if (!$premium) {
    $r = fg_resolve_paid_access($email, $user);
    if ($r['grant']) {
        $s = $r['sub']; $end = $r['end']; $subId = $r['sub_id']; $status = $s['status'] ?? '';
        $user['premium_until'] = date('c', $end + 24 * 3600); // 1-day grace
        $user['subscription_id'] = $subId;
        $user['source'] = 'razorpay_web'; $user['plan'] = 'monthly';
        if ($status === 'cancelled') $user['cancelled'] = $user['cancelled'] ?? date('c'); // paid period runs out, no renewal
        unset($user['pending_subscription']);
        // First activation → alert the owner: a new subscriber paid.
        if (empty($user['owner_notified'])) {
            $user['owner_notified'] = true;
            $sec = fg_secrets();
            $key = $sec['razorpay_key_id'] ?? ''; $secret = $sec['razorpay_key_secret'] ?? '';
            $cname = ''; $cphone = '';
            if (!empty($s['customer_id']) && $key && $secret) {
                $ch2 = curl_init('https://api.razorpay.com/v1/customers/' . rawurlencode($s['customer_id']));
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
                . '<b>Subscription:</b> ' . htmlspecialchars((string)$subId) . '<br>'
                . '<b>Status:</b> ' . htmlspecialchars($status) . '<br>'
                . '<b>Paid till:</b> ' . date('d M Y', $end) . '</p></div>', $h);
        }
        fg_save_user($email, $user);
        $until = $user['premium_until'];
        $premium = true;
    }
}

// Referral trial (7 days per invited friend, auto-expires: nothing to switch off).
require_once __DIR__ . '/ref-lib.php';
$trial = false;
if (!$premium && ref_trial_active($user)) { $premium = true; $trial = true; $until = $user['trial_until']; }

// Answer Writing tier (₹999): cached on the record, re-resolved when missing/expired.
require_once __DIR__ . '/aw-lib.php';
$aw = aw_entitled($email, $user);
if ($aw && !$premium) { $premium = true; $until = $user['premium_until'] ?? $user['aw_until']; }
fg_json(200, [
    'premium' => $premium,
    'premiumUntil' => $until,
    'aw' => $aw,
    'awUntil' => $user['aw_until'] ?? null,
    'plan' => $trial ? 'trial' : ($premium ? ($user['plan'] ?? 'monthly') : null),
    'trial' => $trial,
    'trialUntil' => $user['trial_until'] ?? null,
    'source' => $trial ? 'trial' : ($user['source'] ?? null),
    'name' => $user['name'] ?? null,
]);
