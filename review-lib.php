<?php
/**
 * Google Play review login.
 *
 * Play reviewers cannot open our emailed login codes, so ONE fixed address signs in
 * with ONE fixed 6-digit code (entered in Play Console > App content > App access):
 *  - The code is made once at random and kept only in FG_DATA_DIR/review-login.json
 *    (outside public_html, never in git). It is emailed once, to the owner only.
 *  - No email is ever sent to the review address: send-otp.php only issues the token.
 *  - 20 wrong codes within 24 hours lock the address until those 24 hours have passed
 *    (a code that never changes must not be found by trying all million). The owner
 *    is told by email when that happens.
 *  - The account has Premium and Answer Writing (reviewers must be able to reach every
 *    screen). It is never on the All-India board other students see and never in its
 *    counts; only the reviewer's own view shows the reviewer's row.
 * New code: raise FG_REVIEW_GEN (or delete FG_DATA_DIR/review-login.json), deploy, then
 * request a code once for the review address; the owner gets the new code by email.
 */
require_once __DIR__ . '/fg-config.php';

const FG_REVIEW_EMAIL = 'playreview@netmock.com';
const FG_REVIEW_OWNER = 'netmockias@gmail.com';
const FG_REVIEW_MAX_FAILS = 20;
const FG_REVIEW_GEN = 1;                                   // raise to make (and email) a new code

function fg_is_review($email) { return strtolower(trim((string)$email)) === FG_REVIEW_EMAIL; }
function fg_review_path() { return FG_DATA_DIR . '/review-login.json'; }
function fg_review_state() {
    $p = fg_review_path();
    $j = is_readable($p) ? json_decode((string)file_get_contents($p), true) : null;
    return is_array($j) ? $j : [];
}
function fg_review_save($s) { file_put_contents(fg_review_path(), json_encode($s), LOCK_EX); }
// One request at a time touches review-login.json (creating the code, counting wrong codes).
function fg_review_lock() {
    if (!is_dir(FG_DATA_DIR)) mkdir(FG_DATA_DIR, 0755, true);
    $ht = FG_DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\n");
    $fh = fopen(FG_DATA_DIR . '/review-login.lock', 'c');
    if ($fh) flock($fh, LOCK_EX);
    return $fh;
}
function fg_review_unlock($fh) { if ($fh) { flock($fh, LOCK_UN); fclose($fh); } }
function fg_review_mail($subject, $body) {
    $html = '<div style="font-family:sans-serif;max-width:460px;margin:auto;padding:16px;">'
          . '<h2 style="color:#7c3aed;">FlashGenius</h2>' . $body . '</div>';
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: FlashGenius <no-reply@netmock.com>\r\n";
    return @mail(FG_REVIEW_OWNER, $subject, $html, $headers);
}

function fg_review_ready($s) { return preg_match('/^\d{6}$/', (string)($s['code'] ?? '')) && (int)($s['gen'] ?? 0) === FG_REVIEW_GEN && !empty($s['emailed']); }
// The fixed code. Made, and emailed to the owner, the first time it is asked for
// (the email is tried again on the next request if sending failed).
function fg_review_code() {
    $s = fg_review_state();
    if (fg_review_ready($s)) return $s['code'];
    $fh = fg_review_lock();
    $s = fg_review_state();                              // another request may have made it meanwhile
    if (!preg_match('/^\d{6}$/', (string)($s['code'] ?? '')) || (int)($s['gen'] ?? 0) !== FG_REVIEW_GEN) {
        $s = ['email' => FG_REVIEW_EMAIL, 'gen' => FG_REVIEW_GEN, 'code' => str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT),
              'created' => date('c'), 'emailed' => false, 'fails' => []];
        fg_review_save($s);
    }
    if (empty($s['emailed'])) {
        $ok = fg_review_mail('FlashGenius: login for the Google Play review',
            '<p>Login for Google Play reviewers. Enter it in Play Console, App content, App access.</p>'
            . '<p>Email: <b>' . FG_REVIEW_EMAIL . '</b><br>Code: <b style="font-size:26px;letter-spacing:5px;">' . $s['code'] . '</b></p>'
            . '<p style="color:#555;">The code does not expire. This account has Premium and is never shown on the leaderboard. '
            . 'Please keep this email private.</p>');
        $s['emailed'] = $ok ? date('c') : false;
        fg_review_save($s);
    }
    fg_review_unlock($fh);
    return $s['code'];
}
function fg_review_recent_fails($s) {
    $cut = time() - 86400;
    return array_values(array_filter((array)($s['fails'] ?? []), function ($t) use ($cut) { return (int)$t > $cut; }));
}
function fg_review_locked() { return count(fg_review_recent_fails(fg_review_state())) >= FG_REVIEW_MAX_FAILS; }
function fg_review_fail() {
    $fh = fg_review_lock();
    $s = fg_review_state();
    $f = fg_review_recent_fails($s); $f[] = time();
    $s['fails'] = array_slice($f, -50);
    fg_review_save($s);
    fg_review_unlock($fh);
    if (count($f) === FG_REVIEW_MAX_FAILS) {
        fg_review_mail('FlashGenius: Play review login locked for 24 hours',
            '<p>Someone entered a wrong code ' . FG_REVIEW_MAX_FAILS . ' times in 24 hours for <b>' . FG_REVIEW_EMAIL . '</b>, '
            . 'so that login is locked until 24 hours after the first of those tries. It unlocks by itself.</p>'
            . '<p style="color:#555;">If a Google Play review is running right now, ask Claude to unlock it.</p>');
    }
}
// Premium and Answer Writing for the review account; no payment records are involved.
function fg_review_entitlement() {
    $until = date('c', time() + 365 * 86400);
    return ['premium' => true, 'premiumUntil' => $until, 'aw' => true, 'awUntil' => $until, 'plan' => 'review',
            'trial' => false, 'trialUntil' => null, 'source' => 'review', 'subscriptionId' => null,
            'verified' => true, '_newSub' => false];
}
