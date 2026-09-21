<?php
/**
 * Referral programme: "invite a friend, both get 7 days Premium".
 *  - Every signed-in student has a fixed 6-letter code (from their email + secret).
 *  - The friend types the code in the app BEFORE their first test (ref-claim.php).
 *  - The moment the friend finishes their first ranked test (lb-submit.php),
 *    BOTH accounts get trial_until = max(now, trial_until) + 7 days.
 *  - Premium is decided by entitlement.php on every launch: paid OR trial_until
 *    in the future. Nothing to switch off: on day 8 the answer is simply "no".
 * Guardrails: one claim per new account, never your own code, the friend must be
 * a genuinely new account (no ranked tests, never paid), referrer capped at
 * REF_MAX_DAYS of free time in total.
 * Storage: FG_DATA_DIR/ref/codes/<CODE>.txt -> email ; fields on the fg user record:
 *   ref_by (referrer email), ref_claimed_at, ref_rewarded (bool),
 *   ref_days_earned, ref_count, trial_until (ISO date-time)
 */
require_once __DIR__ . '/fg-config.php';
const REF_TRIAL_DAYS = 7;
const REF_MAX_DAYS = 30;

function ref_code($email) {
    $sec = fg_secrets();
    $h = hash_hmac('sha256', strtolower(trim($email)), ($sec['session_hmac_key'] ?? 'dev') . '|ref');
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I
    $out = '';
    for ($i = 0; $i < 6; $i++) $out .= $alpha[hexdec(substr($h, $i * 2, 2)) % strlen($alpha)];
    return $out;
}
function ref_dir() { $d = FG_DATA_DIR . '/ref/codes'; if (!is_dir($d)) mkdir($d, 0755, true); return $d; }
function ref_register($email) { // make the code resolvable
    $c = ref_code($email); $p = ref_dir() . '/' . $c . '.txt';
    if (!is_file($p)) file_put_contents($p, strtolower(trim($email)), LOCK_EX);
    return $c;
}
function ref_resolve($code) {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$code));
    if (strlen($code) !== 6) return null;
    $p = ref_dir() . '/' . $code . '.txt';
    return is_readable($p) ? trim(file_get_contents($p)) : null;
}
function ref_trial_active($user) {
    return !empty($user['trial_until']) && strtotime($user['trial_until']) > time();
}
function ref_add_days(&$user, $days) {
    $base = ref_trial_active($user) ? strtotime($user['trial_until']) : time();
    $user['trial_until'] = date('c', $base + $days * 86400);
}
// Called from lb-submit when a student's first ranked test lands. Returns
// ['rewarded'=>bool, 'days'=>n] for the claimer.
function ref_on_first_test($email) {
    $u = fg_load_user($email) ?: [];
    if (empty($u['ref_by']) || !empty($u['ref_rewarded'])) return ['rewarded' => false];
    $refEmail = strtolower($u['ref_by']);
    $r = fg_load_user($refEmail) ?: ['email' => $refEmail];
    $earned = (int)($r['ref_days_earned'] ?? 0);
    // Friend always gets the week; referrer only while under the cap.
    ref_add_days($u, REF_TRIAL_DAYS);
    $u['ref_rewarded'] = true; $u['ref_rewarded_at'] = date('c');
    if (empty($u['source'])) $u['source'] = 'trial';
    fg_save_user($email, $u);
    $r['ref_count'] = (int)($r['ref_count'] ?? 0) + 1;
    if ($earned < REF_MAX_DAYS) {
        $give = min(REF_TRIAL_DAYS, REF_MAX_DAYS - $earned);
        ref_add_days($r, $give);
        $r['ref_days_earned'] = $earned + $give;
    }
    fg_save_user($refEmail, $r);
    return ['rewarded' => true, 'days' => REF_TRIAL_DAYS];
}
