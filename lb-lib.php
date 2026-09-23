<?php
/**
 * All-India leaderboard: shared helpers.
 * Storage (outside public_html):
 *   FG_DATA_DIR/lb/users/<sha1 email>.json   totals per student
 *   FG_DATA_DIR/lb/attempts/<sha1 email>/<ck>-<v>.json   one file per set taken (first attempt only)
 *   FG_DATA_DIR/lb/board.json                cached board (60 s)
 */
require_once __DIR__ . '/fg-config.php';
function lb_cfg() { static $c = null; if ($c === null) $c = include __DIR__ . '/lb-config.php'; return $c; }
function lb_dir() { return FG_DATA_DIR . '/lb'; }
function lb_uid($email) { return sha1(strtolower(trim($email))); }
function lb_user_path($email) { return lb_dir() . '/users/' . lb_uid($email) . '.json'; }
function lb_load_user($email) {
    $p = lb_user_path($email);
    if (!is_readable($p)) return null;
    $j = json_decode(file_get_contents($p), true);
    return is_array($j) ? $j : null;
}
function lb_save_user($email, $u) {
    $d = lb_dir() . '/users';
    if (!is_dir($d)) mkdir($d, 0755, true);
    $ht = FG_DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\n");
    $u['updated'] = date('c');
    file_put_contents(lb_user_path($email), json_encode($u, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
// "Rahul Mehta" -> "Rahul M."; email-only -> "Rahul"; keeps Devanagari names intact.
// Letters only (any script); digits and symbols from email ids are dropped, never "Name .".
function lb_display_name($name, $email) {
    $clean = function ($t) { return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{M}\s]+/u', ' ', (string)$t))); };
    $name = $clean(strpos((string)$name, '@') !== false ? '' : $name);
    if ($name === '') $name = $clean(preg_replace('/[^a-z]+/i', ' ', explode('@', (string)$email)[0]));
    if ($name === '') return 'Student';
    $parts = preg_split('/\s+/u', $name);
    $first = mb_convert_case(mb_substr($parts[0], 0, 18), MB_CASE_TITLE, 'UTF-8');
    if (count($parts) > 1) { $last = mb_substr(end($parts), 0, 1); if ($last !== '') return $first . ' ' . mb_strtoupper($last) . '.'; }
    return $first;
}
// Names stored by the old rule ("Kiranluthra .") read cleanly without touching the files.
function lb_clean_stored_name($n) { $n = trim(preg_replace('/\s+\.$/u', '', (string)$n)); return $n !== '' ? $n : 'Student'; }
function lb_is_mentor($email) { return in_array(strtolower(trim($email)), array_map('strtolower', lb_cfg()['mentor_emails']), true); }
function lb_month_key($ts) { return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m'); }

// Same rule as the app's correctOptionIndex(): answer letter, exact text, or contained text.
function lb_norm($s) { $s = mb_strtolower((string)$s); $s = preg_replace('/^[a-d][).:\-]\s*/', '', $s); $s = preg_replace('/[^a-z0-9 ]/', ' ', $s); return trim(preg_replace('/\s+/', ' ', $s)); }
function lb_correct_index($card) {
    $opts = isset($card['options']) && is_array($card['options']) ? $card['options'] : [];
    if (!$opts) return -1;
    $ans = trim((string)($card['answer'] ?? ''));
    if (preg_match('/^([A-Da-d])[).:\-]?$/', $ans, $m)) { $i = strpos('abcd', strtolower($m[1])); if ($i !== false && $i < count($opts)) return $i; }
    $na = lb_norm($ans); if ($na === '') return -1;
    foreach ($opts as $i => $o) if (lb_norm($o) === $na) return $i;
    if (mb_strlen($na) >= 4) foreach ($opts as $i => $o) { $no = lb_norm($o); if ($no !== '' && (strpos($no, $na) !== false || strpos($na, $no) !== false)) return $i; }
    return -1;
}
// Fetch a deck exactly as the app does (GitHub raw, 6 h disk cache).
function lb_fetch_deck($ck, $v) {
    $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $ck) . ($v > 1 ? "-$v" : '') . '.json';
    $dir = FG_DATA_DIR . '/ghpool2'; if (!is_dir($dir)) mkdir($dir, 0755, true);
    $f = $dir . '/' . $name;
    if (is_readable($f) && (time() - filemtime($f)) < 21600) { $s = file_get_contents($f); if ($s !== 'MISS') { $j = json_decode($s, true); if (is_array($j)) return $j; } }
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $s = @file_get_contents('https://raw.githubusercontent.com/GodLike0to1/flashgenius-data/refs/heads/main/decks/' . $name, false, $ctx);
    $j = $s !== false ? json_decode($s, true) : null;
    if (is_array($j) && count($j)) { file_put_contents($f, $s, LOCK_EX); return $j; }
    if (is_readable($f)) { $j = json_decode(file_get_contents($f), true); if (is_array($j)) return $j; }
    return null;
}
// Build (or read the 60 s cache of) the All-India board.
function lb_board($force = false) {
    $cache = lb_dir() . '/board.json';
    if (!$force && is_readable($cache) && (time() - filemtime($cache)) < 60) { $j = json_decode(file_get_contents($cache), true); if (is_array($j)) return $j; }
    $rows = []; $mentors = []; $testsAll = 0; $testsMonth = 0; $month = lb_month_key(time());
    foreach (glob(lb_dir() . '/users/*.json') ?: [] as $p) {
        $u = json_decode(file_get_contents($p), true);
        if (!is_array($u) || empty($u['email'])) continue;
        $testsAll += (int)($u['tests'] ?? 0);
        $testsMonth += (int)($u['months'][$month]['tests'] ?? 0);
        $answered = (int)($u['correct'] ?? 0) + (int)($u['wrong'] ?? 0);
        $row = [
            'uid' => lb_uid($u['email']), 'name' => lb_clean_stored_name($u['name'] ?? ''),
            'points' => round((float)($u['points'] ?? 0), 2), 'tests' => (int)($u['tests'] ?? 0),
            'accuracy' => $answered ? (int)round(100 * (int)$u['correct'] / $answered) : 0,
            'avgTime' => (int)($u['tests'] ?? 0) ? (int)round((int)($u['seconds'] ?? 0) / (int)$u['tests']) : 0,
            'monthPoints' => round((float)($u['months'][$month]['points'] ?? 0), 2),
        ];
        if (lb_is_mentor($u['email'])) { $row['mentor'] = true; $mentors[] = $row; continue; }
        // Ranked once the student has answered anything: a full set, or the free 1-question previews.
        if (!empty($u['hide']) || ((int)($u['tests'] ?? 0) < 1 && $answered < 1)) continue;
        $rows[] = $row;
    }
    usort($rows, function ($a, $b) {
        if ($a['points'] != $b['points']) return $b['points'] <=> $a['points'];
        if ($a['accuracy'] != $b['accuracy']) return $b['accuracy'] <=> $a['accuracy'];
        return $a['avgTime'] <=> $b['avgTime'];
    });
    foreach ($rows as $i => &$r) $r['rank'] = $i + 1; unset($r);
    $board = ['builtAt' => date('c'), 'students' => count($rows), 'testsAll' => $testsAll, 'testsMonth' => $testsMonth,
              'rows' => $rows, 'mentors' => $mentors];
    if (!is_dir(lb_dir())) mkdir(lb_dir(), 0755, true);
    file_put_contents($cache, json_encode($board, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $board;
}
// The caller's own row, rank and percentile from a built board.
function lb_me($board, $email) {
    $uid = lb_uid($email); $n = count($board['rows']);
    foreach ($board['rows'] as $r) if ($r['uid'] === $uid) {
        $below = $n - $r['rank'];
        return ['rank' => $r['rank'], 'of' => $n, 'percentile' => $n > 1 ? (int)round(100 * $below / ($n - 1)) : 100,
                'points' => $r['points'], 'tests' => $r['tests'], 'accuracy' => $r['accuracy'], 'name' => $r['name']];
    }
    foreach ($board['mentors'] as $r) if ($r['uid'] === $uid) return ['mentor' => true, 'points' => $r['points'], 'tests' => $r['tests'], 'accuracy' => $r['accuracy'], 'name' => $r['name']];
    $u = lb_load_user($email);
    if ($u && !empty($u['hide'])) return ['hidden' => true, 'points' => round((float)$u['points'], 2), 'tests' => (int)$u['tests']];
    return null;
}
// Month-over-month accuracy per subject. Needs >= 8 answers in both this
// month and the previous one; returns best improvements first.
function lb_progress_view($u) {
    $cur = lb_month_key(time()); $prev = (new DateTime($cur . '-01'))->modify('-1 month')->format('Y-m');
    $out = [];
    foreach (($u['subjectsMonth'][$cur] ?? []) as $k => $v) {
        $p = $u['subjectsMonth'][$prev][$k] ?? null; if (!$p) continue;
        $a1 = (int)$v['correct'] + (int)$v['wrong']; $a0 = (int)$p['correct'] + (int)$p['wrong'];
        if ($a1 < 8 || $a0 < 8) continue;
        [$cls, $sub] = array_pad(explode('|', $k, 2), 2, '');
        $from = (int)round(100 * (int)$p['correct'] / $a0); $to = (int)round(100 * (int)$v['correct'] / $a1);
        $out[] = ['cls' => $cls, 'subject' => $sub, 'from' => $from, 'to' => $to, 'delta' => $to - $from, 'answered' => $a1];
    }
    usort($out, function ($a, $b) { return $b['delta'] <=> $a['delta']; });
    return $out;
}
function lb_mine($email) { // caller-only extras shipped next to 'me'
    $u = lb_load_user($email) ?: [];
    $prem = lb_is_premium($email);
    return ['streak' => lb_streak_view($u, $prem), 'subjects' => lb_subjects_view($u), 'progress' => lb_progress_view($u), 'premium' => $prem];
}
function lb_public($board, $email) {
    $cfg = lb_cfg();
    $rows = array_slice($board['rows'], 0, (int)$cfg['top_n']);
    $strip = function ($r) { unset($r['uid']); return $r; };
    return [
        'students' => $board['students'], 'showCount' => $board['students'] >= (int)$cfg['show_count_from'],
        'testsAll' => $board['testsAll'], 'testsMonth' => $board['testsMonth'],
        'rows' => array_map($strip, $rows), 'mentors' => array_map($strip, $board['mentors']),
        'me' => $email ? lb_me($board, $email) : null,
        'mine' => $email ? lb_mine($email) : null,
        'toppers' => array_map($strip, array_slice($board['rows'], 0, 10)),
        'builtAt' => $board['builtAt'],
    ];
}

// ---- Daily streak (IST). One finished test a day keeps it alive. Premium
// students get ONE automatic freeze per ISO week: a single missed day does
// not break the chain. Stored on the lb user record as
//   streak: {days, best, last:'Y-m-d', freezeWeek:'o-W', frozen:['Y-m-d', ...]}
function lb_ist_date($ts = null) { return (new DateTime('@' . ($ts ?? time())))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d'); }
function lb_touch_streak(&$u, $premium) {
    $st = $u['streak'] ?? ['days' => 0, 'best' => 0, 'last' => null, 'freezeWeek' => null, 'frozen' => []];
    $today = lb_ist_date();
    if (($st['last'] ?? null) === $today) { $u['streak'] = $st; return $st; }
    $y1 = lb_ist_date(time() - 86400); $y2 = lb_ist_date(time() - 2 * 86400);
    $week = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('o-W');
    $usedFreeze = false;
    if ($st['last'] === $y1) $st['days'] = (int)$st['days'] + 1;
    elseif ($premium && $st['last'] === $y2 && ($st['freezeWeek'] ?? null) !== $week) {
        $st['days'] = (int)$st['days'] + 1; $st['freezeWeek'] = $week; $st['frozen'][] = $y1; $usedFreeze = true;
        $st['frozen'] = array_slice($st['frozen'], -30);
    } else $st['days'] = 1;
    $st['last'] = $today;
    $st['best'] = max((int)($st['best'] ?? 0), (int)$st['days']);
    $st['freezeUsedNow'] = $usedFreeze;
    $st['freezeAvailable'] = $premium && ($st['freezeWeek'] ?? null) !== $week;
    $u['streak'] = $st;
    return $st;
}
// Streak as the app should display it right now (a chain that was not
// continued yesterday is already broken, even before today's test).
function lb_streak_view($u, $premium) {
    $st = $u['streak'] ?? null;
    if (!$st) return ['days' => 0, 'best' => 0, 'today' => false, 'freezeAvailable' => $premium];
    $today = lb_ist_date(); $y1 = lb_ist_date(time() - 86400); $y2 = lb_ist_date(time() - 2 * 86400);
    $week = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('o-W');
    $freezeAvail = $premium && ($st['freezeWeek'] ?? null) !== $week;
    $alive = $st['last'] === $today || $st['last'] === $y1 || ($freezeAvail && $st['last'] === $y2);
    return ['days' => $alive ? (int)$st['days'] : 0, 'best' => (int)($st['best'] ?? 0), 'today' => $st['last'] === $today,
            'atRisk' => $alive && $st['last'] !== $today, 'freezeAvailable' => $freezeAvail, 'last' => $st['last']];
}
// Is this student premium right now (paid, Answer Writing or referral trial)?
function lb_is_premium($email) {
    $f = fg_load_user($email); if (!$f) return false;
    foreach (['premium_until', 'aw_until', 'trial_until'] as $k) if (!empty($f[$k]) && strtotime($f[$k]) > time()) return true;
    return false;
}
// Per-subject accuracy → weak areas. subjects: {"UPSC Prelims|Polity": {correct,wrong,skipped,tests}}
function lb_subjects_view($u) {
    $out = [];
    foreach (($u['subjects'] ?? []) as $k => $v) {
        $ans = (int)$v['correct'] + (int)$v['wrong'];
        [$cls, $sub] = array_pad(explode('|', $k, 2), 2, '');
        $out[] = ['cls' => $cls, 'subject' => $sub, 'tests' => (int)$v['tests'], 'answered' => $ans,
                  'accuracy' => $ans ? (int)round(100 * (int)$v['correct'] / $ans) : 0];
    }
    usort($out, function ($a, $b) { return $a['accuracy'] <=> $b['accuracy'] ?: $b['answered'] <=> $a['answered']; });
    return $out;
}
