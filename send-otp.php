<?php
// POST {email} → emails a 6-digit login code; returns {token} (stateless HMAC).
// The Google Play review address gets no email: its code is fixed (review-lib.php).
require __DIR__ . '/fg-config.php';
require_once __DIR__ . '/review-lib.php';
fg_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_json(405, ['error' => 'POST only']);
$b = fg_body();
$email = strtolower(trim($b['email'] ?? ''));
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) fg_json(400, ['error' => 'Please enter a valid email address.']);
$sec = fg_secrets();
$review = fg_is_review($email);
$code = $review ? fg_review_code() : str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$exp = time() + 600;
$mac = hash_hmac('sha256', 'otp|' . $email . '|' . $code . '|' . $exp, $sec['session_hmac_key'] ?? 'dev');
$token = $exp . '.' . $mac;
if ($review) fg_json(200, ['token' => $token]);
$subject = $code . ' is your FlashGenius login code';
$html = '<div style="font-family:sans-serif;max-width:420px;margin:auto;padding:16px;">'
      . '<h2 style="color:#7c3aed;">FlashGenius</h2><p>Your login code is:</p>'
      . '<p style="font-size:32px;font-weight:bold;letter-spacing:6px;">' . $code . '</p>'
      . '<p style="color:#666;">Valid for 10 minutes. If you didn\'t request this, ignore this email.</p></div>';
$headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: FlashGenius <no-reply@netmock.com>\r\n";
$ok = @mail($email, $subject, $html, $headers);
if (!$ok) fg_json(500, ['error' => 'Could not send the email. Please try again.']);
fg_json(200, ['token' => $token]);
