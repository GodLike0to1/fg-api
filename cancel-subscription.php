<?php
// POST {email, session} → cancels the subscription at cycle end.
require __DIR__ . '/fg-config.php';
fg_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_json(405, ['error' => 'POST only']);
$b = fg_body();
$email = strtolower(trim($b['email'] ?? ''));
if (!$email || !fg_check_session($email, $b['session'] ?? '')) fg_json(401, ['error' => 'Not signed in']);
$user = fg_load_user($email) ?: [];
$subId = $user['subscription_id'] ?? ($user['pending_subscription'] ?? null);
if (!$subId) fg_json(404, ['error' => 'No active subscription found for this account.']);
$sec = fg_secrets();
$ch = curl_init('https://api.razorpay.com/v1/subscriptions/' . rawurlencode($subId) . '/cancel');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => json_encode(['cancel_at_cycle_end' => 1]),
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_USERPWD => ($sec['razorpay_key_id'] ?? '') . ':' . ($sec['razorpay_key_secret'] ?? ''),
  CURLOPT_TIMEOUT => 30]);
$resp = json_decode((string)curl_exec($ch), true);
$codeHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($codeHttp >= 400) fg_json($codeHttp, ['error' => $resp['error']['description'] ?? 'Could not cancel — try again or contact support.']);
$user['cancelled_at_cycle_end'] = true;
fg_save_user($email, $user);
fg_json(200, ['ok' => true, 'status' => $resp['status'] ?? 'cancelled']);
