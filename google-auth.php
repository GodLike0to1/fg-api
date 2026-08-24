<?php
/**
 * POST {idToken} → verifies the Google ID token, returns {email, session, premiumUntil}.
 * The app calls this after native Google Sign-In. The Gmail is the unique ID.
 */
require __DIR__ . '/fg-config.php';
fg_preflight();
// GET → the public web client ID for the Google sign-in button (not a secret).
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sec = fg_secrets();
    fg_json(200, ['clientId' => $sec['google_web_client_id'] ?? null]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_json(405, ['error' => 'POST only']);

$b = fg_body();
$idToken = $b['idToken'] ?? '';
if (!$idToken) fg_json(400, ['error' => 'Missing idToken']);

$resp = @file_get_contents('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
$info = $resp ? json_decode($resp, true) : null;
if (!$info || empty($info['email'])) fg_json(401, ['error' => 'Invalid Google token']);

$sec = fg_secrets();
$aud = $sec['google_web_client_id'] ?? '';
if ($aud && ($info['aud'] ?? '') !== $aud) fg_json(401, ['error' => 'Token audience mismatch']);
if (($info['email_verified'] ?? 'false') !== 'true' && ($info['email_verified'] ?? false) !== true) {
    fg_json(401, ['error' => 'Email not verified']);
}
if (isset($info['exp']) && (int)$info['exp'] < time()) fg_json(401, ['error' => 'Token expired']);

$email = strtolower($info['email']);
$user = fg_load_user($email) ?: [];
if (empty($user['created'])) $user['created'] = date('c');
fg_save_user($email, $user);

fg_json(200, [
    'email' => $email,
    'session' => fg_make_session($email),
    'premiumUntil' => $user['premium_until'] ?? null,
]);
