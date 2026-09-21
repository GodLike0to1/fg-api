<?php
/** POST {email, session, hide:bool} → hide or show the student on the board. */
require __DIR__ . '/lb-lib.php';
fg_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_json(405, ['error' => 'POST only']);
$b = fg_body();
$email = strtolower(trim($b['email'] ?? ''));
if (!$email || !fg_check_session($email, $b['session'] ?? '')) fg_json(401, ['error' => 'Not signed in']);
$u = lb_load_user($email) ?: ['email' => $email, 'points' => 0, 'tests' => 0, 'correct' => 0, 'wrong' => 0, 'skipped' => 0, 'seconds' => 0, 'months' => []];
$u['hide'] = !empty($b['hide']);
if (!empty($b['name']) && empty($u['name'])) $u['name'] = lb_display_name($b['name'], $email);
lb_save_user($email, $u);
lb_board(true);
fg_json(200, ['ok' => true, 'hide' => $u['hide']]);
