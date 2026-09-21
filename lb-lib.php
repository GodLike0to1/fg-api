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
function lb_display_name($name, $email) {
    $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
    if ($name === '') $name = ucfirst(preg_replace('/[^a-z]/i', ' ', explode('@', $email)[0]));
    $parts = preg_split('/\s+/u', $name);
    $first = mb_substr($parts[0], 0, 18);
    if (count($parts) > 1) { $last = mb_substr(end($parts), 0, 1); return $first . ' ' . mb_strtoupper($last) . '.'; }
    return $first;
}
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
            'uid' => lb_uid($u['email']), 'name' => $u['name'] ?? 'Student',
            'points' => round((float)($u['points'] ?? 0), 2), 'tests' => (int)($u['tests'] ?? 0),
            'accuracy' => $answered ? (int)round(100 * (int)$u['correct'] / $answered) : 0,
            'avgTime' => (int)($u['tests'] ?? 0) ? (int)round((int)($u['seconds'] ?? 0) / (int)$u['tests']) : 0,
            'monthPoints' => round((float)($u['months'][$month]['points'] ?? 0), 2),
        ];
        if (lb_is_mentor($u['email'])) { $row['mentor'] = true; $mentors[] = $row; continue; }
        if (!empty($u['hide']) || (int)($u['tests'] ?? 0) < 1) continue;
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
function lb_public($board, $email) {
    $cfg = lb_cfg();
    $rows = array_slice($board['rows'], 0, (int)$cfg['top_n']);
    $strip = function ($r) { unset($r['uid']); return $r; };
    return [
        'students' => $board['students'], 'showCount' => $board['students'] >= (int)$cfg['show_count_from'],
        'testsAll' => $board['testsAll'], 'testsMonth' => $board['testsMonth'],
        'rows' => array_map($strip, $rows), 'mentors' => array_map($strip, $board['mentors']),
        'me' => $email ? lb_me($board, $email) : null,
        'builtAt' => $board['builtAt'],
    ];
}
