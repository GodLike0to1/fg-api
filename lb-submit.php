<?php
/**
 * POST {email, session, name, ck, v, answers:[optionIndex|-1 ...], seconds}
 * Marks the set on the server (UPSC style) and adds it to the student's
 * All-India total. Only the FIRST attempt at a given set counts.
 * → {ranked, points, correct, wrong, skipped, board:{me,...}}
 */
require __DIR__ . '/lb-lib.php';
fg_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_json(405, ['error' => 'POST only']);
$b = fg_body();
$email = strtolower(trim($b['email'] ?? ''));
if (!$email || !fg_check_session($email, $b['session'] ?? '')) fg_json(401, ['error' => 'Not signed in']);
$ck = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($b['ck'] ?? ''));
$v = max(1, (int)($b['v'] ?? 1));
$answers = $b['answers'] ?? null;
$seconds = max(0, (int)($b['seconds'] ?? 0));
$preview = !empty($b['preview']);                       // free student's 1-question taste of a locked set
$cls = mb_substr(trim((string)($b['cls'] ?? '')), 0, 40);
$subject = mb_substr(trim((string)($b['subject'] ?? '')), 0, 40);
require_once __DIR__ . '/ref-lib.php';
if (!$ck || !is_array($answers) || !count($answers)) fg_json(400, ['error' => 'ck and answers required']);

$deck = lb_fetch_deck($ck, $v);
if (!$deck) fg_json(404, ['error' => 'Set not found', 'ranked' => false]);
$cfg = lb_cfg();
$n = min(count($deck), count($answers));
$correct = 0; $wrong = 0; $skipped = 0;
for ($i = 0; $i < $n; $i++) {
    $a = (int)$answers[$i];
    if ($a < 0) { $skipped++; continue; }
    if ($a === lb_correct_index($deck[$i])) $correct++; else $wrong++;
}
$points = round($correct * (float)$cfg['marks_correct'] + $wrong * (float)$cfg['marks_wrong'] + $skipped * (float)$cfg['marks_skip'], 2);
$tooFast = $seconds < (int)$cfg['min_seconds_per_question'] * $n;

$adir = lb_dir() . '/attempts/' . lb_uid($email);
if (!is_dir($adir)) mkdir($adir, 0755, true);
$afile = $adir . '/' . $ck . '-' . $v . ($preview ? '-p' : '') . '.json';
$already = is_file($afile);
$attempt = ['ck' => $ck, 'v' => $v, 'n' => $n, 'correct' => $correct, 'wrong' => $wrong, 'skipped' => $skipped,
            'points' => $points, 'seconds' => $seconds, 'at' => date('c'), 'ranked' => !$already && !$tooFast];
if (!$already) file_put_contents($afile, json_encode($attempt), LOCK_EX);

$u = lb_load_user($email) ?: ['email' => $email, 'points' => 0, 'tests' => 0, 'correct' => 0, 'wrong' => 0, 'skipped' => 0, 'seconds' => 0, 'months' => []];
$premium = lb_is_premium($email);
$firstTest = (int)($u['tests'] ?? 0) === 0;
$streak = lb_touch_streak($u, $premium);           // any finished test keeps the streak alive
if ($subject !== '' && !$tooFast) {                  // weak-area stats (previews included, they are real answers)
    $k = $cls . '|' . $subject;
    $sv = $u['subjects'][$k] ?? ['correct' => 0, 'wrong' => 0, 'skipped' => 0, 'tests' => 0];
    $sv['correct'] += $correct; $sv['wrong'] += $wrong; $sv['skipped'] += $skipped; if (!$preview) $sv['tests']++;
    $u['subjects'][$k] = $sv;
    $mk2 = lb_month_key(time());
    $mv = $u['subjectsMonth'][$mk2][$k] ?? ['correct' => 0, 'wrong' => 0];
    $mv['correct'] += $correct; $mv['wrong'] += $wrong;
    $u['subjectsMonth'][$mk2][$k] = $mv;
    if (isset($u['subjectsMonth']) && count($u['subjectsMonth']) > 4) { ksort($u['subjectsMonth']); $u['subjectsMonth'] = array_slice($u['subjectsMonth'], -4, null, true); }
}
if (!empty($b['name'])) $u['name'] = lb_display_name($b['name'], $email);
elseif (empty($u['name']) || preg_match('/\s\.$/u', (string)$u['name'])) { $f = fg_load_user($email); if (!empty($f['name'])) $u['name'] = lb_display_name($f['name'], $email); }
if (empty($u['name'])) $u['name'] = lb_display_name('', $email);
if ($attempt['ranked']) {
    $u['points'] = round($u['points'] + $points, 2); if (!$preview) $u['tests']++;
    $u['correct'] += $correct; $u['wrong'] += $wrong; $u['skipped'] += $skipped; if (!$preview) $u['seconds'] += $seconds;
    $mk = lb_month_key(time());
    $m = $u['months'][$mk] ?? ['points' => 0, 'tests' => 0];
    $m['points'] = round($m['points'] + $points, 2); if (!$preview) $m['tests']++;
    $u['months'][$mk] = $m;
}
lb_save_user($email, $u);
$ref = ['rewarded' => false];
if ($attempt['ranked'] && !$preview && $firstTest) $ref = ref_on_first_test($email);
$board = lb_board(true);
fg_json(200, ['ranked' => $attempt['ranked'], 'reason' => $already ? 'already_taken' : ($tooFast ? 'too_fast' : null),
    'points' => $points, 'correct' => $correct, 'wrong' => $wrong, 'skipped' => $skipped, 'n' => $n,
    'preview' => $preview, 'streak' => lb_streak_view($u, $premium), 'referral' => $ref,
    'board' => lb_public($board, $email)]);
