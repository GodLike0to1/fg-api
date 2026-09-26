<?php
// POST {email, code, token} → verifies the emailed code, returns {email, session}
// plus premium status so the app can restore instantly on a new device.
require __DIR__ . '/entitlement-lib.php';
fg_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_json(405, ['error' => 'POST only']);
$b = fg_body();
$email = strtolower(trim($b['email'] ?? ''));
$code = trim($b['code'] ?? '');
$token = (string)($b['token'] ?? '');
if (!$email || !preg_match('/^\d{6}$/', $code) || !$token) fg_json(400, ['error' => 'Enter the 6-digit code from the email.']);
$review = fg_is_review($email);                          // Google Play review login (fixed code, review-lib.php)
if ($review && fg_review_locked()) fg_json(429, ['error' => 'Too many wrong codes. Please try again tomorrow.']);
$parts = explode('.', $token, 2);
if (count($parts) !== 2 || (int)$parts[0] < time()) fg_json(401, ['error' => 'Code expired — request a new one.']);
$sec = fg_secrets();
$want = hash_hmac('sha256', 'otp|' . $email . '|' . $code . '|' . $parts[0], $sec['session_hmac_key'] ?? 'dev');
if (!hash_equals($want, $parts[1])) { if ($review) fg_review_fail(); fg_json(401, ['error' => 'Wrong code. Check the email and try again.']); }
$session = fg_make_session($email);
$user = fg_load_user($email) ?: [];
$e = fg_entitlement($email, $user);   // only paid time counts (no grace), same as entitlement.php
fg_save_user($email, $user);
unset($e['_newSub']);
fg_json(200, array_merge($e, ['email' => $email, 'session' => $session, 'name' => $user['name'] ?? null]));
