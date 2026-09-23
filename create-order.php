<?php
/** POST {email, plan:'q'|'y'} → {orderId, keyId, amount, name} for Razorpay Checkout (one-time, no mandate). */
require __DIR__ . '/onetime-lib.php';
fg_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_json(405, ['error' => 'POST only']);
$b = fg_body();
$email = strtolower(trim($b['email'] ?? ''));
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) fg_json(400, ['error' => 'Valid email required']);
$code = $b['plan'] ?? '';
if (!isset(FG_ONETIME[$code])) fg_json(400, ['error' => 'Unknown plan']);
$P = FG_ONETIME[$code];
$sec = fg_secrets(); $key = $sec['razorpay_key_id'] ?? ''; $secret = $sec['razorpay_key_secret'] ?? '';
if (!$key || !$secret) fg_json(500, ['error' => 'Server not configured']);
$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $key . ':' . $secret, CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['amount' => $P['amount'], 'currency' => 'INR', 'receipt' => substr('fg_' . $code . '_' . sha1($email . microtime()), 0, 40),
        'notes' => ['email' => $email, 'product' => $P['product']]])]);
$resp = json_decode((string)curl_exec($ch), true); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($http < 200 || $http >= 300 || empty($resp['id'])) fg_json(502, ['error' => $resp['error']['description'] ?? 'Could not create order']);
$user = fg_load_user($email) ?: [];
$user['pending_order'] = $resp['id']; $user['pending_order_plan'] = $code; $user['pending_order_at'] = date('c');
fg_save_user($email, $user);
fg_json(200, ['orderId' => $resp['id'], 'keyId' => $key, 'amount' => $P['amount'], 'name' => $P['name'], 'days' => $P['days']]);
