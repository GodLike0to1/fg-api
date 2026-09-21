<?php
/**
 * POST {email, session, code} → {ok, message}
 * Records "this new account was invited by <code owner>". The 7-day reward for
 * both is granted by lb-submit.php when the new account finishes its first test.
 */
require __DIR__ . '/ref-lib.php';
require_once __DIR__ . '/lb-lib.php';
fg_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_json(405, ['error' => 'POST only']);
$b = fg_body();
$email = strtolower(trim($b['email'] ?? ''));
if (!$email || !fg_check_session($email, $b['session'] ?? '')) fg_json(401, ['error' => 'Not signed in']);
$owner = ref_resolve($b['code'] ?? '');
if (!$owner) fg_json(404, ['error' => 'This code does not exist. Check the letters and try again.']);
if ($owner === $email) fg_json(400, ['error' => 'That is your own code. Share it with a friend instead.']);
$u = fg_load_user($email) ?: ['email' => $email];
if (!empty($u['ref_by'])) fg_json(400, ['error' => 'A referral code is already applied on this account.']);
$lb = lb_load_user($email);
if ($lb && (int)($lb['tests'] ?? 0) > 0) fg_json(400, ['error' => 'Referral codes are for new students, before the first test.']);
if (!empty($u['subscription_id']) || (!empty($u['premium_until']) && strtotime($u['premium_until']) > time()))
    fg_json(400, ['error' => 'Referral codes are for new accounts only.']);
$u['ref_by'] = $owner; $u['ref_claimed_at'] = date('c');
fg_save_user($email, $u);
fg_json(200, ['ok' => true, 'message' => 'Code applied. Finish your first MCQ test and you both get 7 days of Premium.']);
