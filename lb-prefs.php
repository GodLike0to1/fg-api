<?php
/** POST {email, session, hide?:bool, name?:string} → hide/show the student on the board and/or set the board name. */
require __DIR__ . '/lb-lib.php';
fg_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_json(405, ['error' => 'POST only']);
$b = fg_body();
$email = strtolower(trim($b['email'] ?? ''));
if (!$email || !fg_check_session($email, $b['session'] ?? '')) fg_json(401, ['error' => 'Not signed in']);
$u = lb_load_user($email) ?: ['email' => $email, 'points' => 0, 'tests' => 0, 'correct' => 0, 'wrong' => 0, 'skipped' => 0, 'seconds' => 0, 'months' => []];
if (array_key_exists('hide', $b)) $u['hide'] = !empty($b['hide']);
$raw = trim((string)($b['name'] ?? ''));
if ($raw !== '') {
    $u['name'] = lb_display_name(mb_substr($raw, 0, 40), $email);
    $f = fg_load_user($email) ?: [];                 // keep the full name on the account too
    $f['name'] = mb_substr(trim(preg_replace('/\s+/u', ' ', $raw)), 0, 40);
    fg_save_user($email, $f);
}
lb_save_user($email, $u);
lb_board(true);
fg_json(200, ['ok' => true, 'hide' => !empty($u['hide']), 'name' => $u['name'] ?? null]);
