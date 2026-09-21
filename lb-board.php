<?php
/** POST {email?, session?} → the All-India board (top 50 + caller's own row). Works signed-out too (no "me"). */
require __DIR__ . '/lb-lib.php';
fg_preflight();
$b = fg_body();
$email = strtolower(trim($b['email'] ?? ''));
if ($email && !fg_check_session($email, $b['session'] ?? '')) $email = '';
fg_json(200, lb_public(lb_board(false), $email));
