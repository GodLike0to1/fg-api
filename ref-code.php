<?php
/** POST {email, session} → {code, link, referred, daysEarned, trialUntil, trialActive} */
require __DIR__ . '/ref-lib.php';
fg_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_json(405, ['error' => 'POST only']);
$b = fg_body();
$email = strtolower(trim($b['email'] ?? ''));
if (!$email || !fg_check_session($email, $b['session'] ?? '')) fg_json(401, ['error' => 'Not signed in']);
$code = ref_register($email);
$u = fg_load_user($email) ?: [];
fg_json(200, [
    'code' => $code,
    'link' => 'https://play.google.com/store/apps/details?id=com.netmock.flashgenius&referrer=' . rawurlencode('utm_source=ref&utm_content=' . $code),
    'referred' => (int)($u['ref_count'] ?? 0),
    'daysEarned' => (int)($u['ref_days_earned'] ?? 0),
    'maxDays' => REF_MAX_DAYS, 'trialDays' => REF_TRIAL_DAYS,
    'trialUntil' => $u['trial_until'] ?? null,
    'trialActive' => ref_trial_active($u),
    'claimed' => !empty($u['ref_by']), 'rewarded' => !empty($u['ref_rewarded']),
]);
