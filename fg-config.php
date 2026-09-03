<?php
/**
 * FlashGenius backend config — upload to public_html/fg-api/ on netmock.com.
 *
 * SECRETS: create fg-secret.php NEXT TO this file (never in git) containing:
 *   <?php return [
 *     'razorpay_key_id'     => 'rzp_live_TQv4vPNlQQkhlw',
 *     'razorpay_key_secret' => 'PASTE_SECRET_HERE',      // Prince pastes this himself
 *     'razorpay_webhook_secret' => 'PASTE_WEBHOOK_SECRET',
 *     'google_web_client_id' => '508581623470-2gb6p6ujigkrdsrpdu9nv95525b01vfj.apps.googleusercontent.com',
 *     'session_hmac_key'    => 'PASTE_ANY_LONG_RANDOM_STRING',
 *   ];
 */
error_reporting(0);

function fg_secrets() {
    static $s = null;
    if ($s === null) {
        // Secrets live OUTSIDE public_html (…/domains/netmock.com/fg-secret.php)
        // so Git auto-deploys can never wipe them. Old in-folder path is a fallback.
        foreach ([dirname(__DIR__, 2) . '/fg-secret.php', __DIR__ . '/fg-secret.php'] as $f) {
            if (is_readable($f)) { $s = include $f; break; }
        }
        if (!is_array($s)) $s = [];
    }
    return $s;
}

// Flat-file store: one JSON file per user, keyed by sha1(email).
// data/ is protected by .htaccess (deny all) — only PHP reads it.
// Student records also live OUTSIDE public_html — deploy-proof and web-unreachable.
define('FG_DATA_DIR', dirname(__DIR__, 2) . '/fg-data');

function fg_store_path($email) {
    return FG_DATA_DIR . '/' . sha1(strtolower(trim($email))) . '.json';
}
function fg_load_user($email) {
    $p = fg_store_path($email);
    if (!is_readable($p)) return null;
    $j = json_decode(file_get_contents($p), true);
    return is_array($j) ? $j : null;
}
function fg_save_user($email, $data) {
    if (!is_dir(FG_DATA_DIR)) { mkdir(FG_DATA_DIR, 0755, true); }
    $ht = FG_DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) file_put_contents($ht, "Require all denied\n");
    $data['email'] = strtolower(trim($email));
    $data['updated'] = date('c');
    file_put_contents(fg_store_path($email), json_encode($data), LOCK_EX);
}

// Session token = HMAC(email + expiry). Stateless, verifiable, no DB.
function fg_make_session($email) {
    $sec = fg_secrets();
    $exp = time() + 180 * 24 * 3600; // 180 days
    $mac = hash_hmac('sha256', strtolower($email) . '|' . $exp, $sec['session_hmac_key'] ?? 'dev');
    return $exp . '.' . $mac;
}
function fg_check_session($email, $token) {
    $sec = fg_secrets();
    $parts = explode('.', (string)$token, 2);
    if (count($parts) !== 2) return false;
    [$exp, $mac] = $parts;
    if ((int)$exp < time()) return false;
    $want = hash_hmac('sha256', strtolower($email) . '|' . $exp, $sec['session_hmac_key'] ?? 'dev');
    return hash_equals($want, $mac);
}

// Resolve a student's paid access from Razorpay, robustly:
//  - finds the subscription even if the local record lost its id (scans by notes.email)
//  - grants for live subs, AND for cancelled/stopped subs whose paid period still runs
//  - period end: current_end → ended_at+31d → current_start+31d → created_at+31d
// Returns ['grant'=>bool,'end'=>epoch,'sub'=>array|null,'sub_id'=>string|null,'why'=>string].
function fg_resolve_paid_access($email, $user) {
    $sec = fg_secrets();
    $key = $sec['razorpay_key_id'] ?? ''; $secret = $sec['razorpay_key_secret'] ?? '';
    $out = ['grant' => false, 'end' => 0, 'sub' => null, 'sub_id' => null, 'why' => ''];
    if (!$key || !$secret) { $out['why'] = 'no razorpay keys'; return $out; }
    $get = function ($path) use ($key, $secret) {
        $ch = curl_init('https://api.razorpay.com/v1' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $key.':'.$secret, CURLOPT_TIMEOUT => 20]);
        $r = json_decode((string)curl_exec($ch), true); curl_close($ch);
        return is_array($r) ? $r : [];
    };
    // Gather every subscription for this student: the id on file PLUS all subs
    // stamped with this email. A student can have several (e.g. a paid one that
    // was cancelled, then a fresh unpaid one created by re-tapping Subscribe) —
    // we must pick the one that actually carries paid access.
    $cands = [];
    $subId = $user['subscription_id'] ?? ($user['pending_subscription'] ?? null);
    if ($subId) { $d0 = $get('/subscriptions/' . rawurlencode($subId)); if (!empty($d0['id'])) $cands[$d0['id']] = $d0; }
    $list = $get('/subscriptions?count=100');
    foreach (($list['items'] ?? []) as $it) {
        if (strtolower(trim($it['notes']['email'] ?? '')) === $email && !empty($it['id'])) $cands[$it['id']] = $it;
    }
    if (!$cands) { $out['why'] = 'no subscription found for email'; return $out; }
    // Rank: live > paid (most recent) > unpaid (most recent).
    $sub = []; $best = -1;
    foreach ($cands as $it) {
        $live = in_array($it['status'] ?? '', ['active', 'authenticated'], true) ? 2e12 : 0;
        $paid = (int)($it['paid_count'] ?? 0) > 0 ? 1e12 : 0;
        $score = $live + $paid + (int)($it['created_at'] ?? 0);
        if ($score > $best) { $best = $score; $sub = $it; }
    }
    $out['sub'] = $sub; $out['sub_id'] = $sub['id'];
    $status = $sub['status'] ?? '';
    $paid = (int)($sub['paid_count'] ?? 0);
    $d = 31 * 24 * 3600;
    $end = !empty($sub['current_end']) ? (int)$sub['current_end'] : 0;
    if (in_array($status, ['active', 'authenticated'], true)) {
        if (!$end || $end < time()) $end = time() + $d;
        $out['grant'] = true; $out['why'] = 'live subscription';
    } elseif ($paid >= 1) {
        if (!$end && !empty($sub['ended_at']))      $end = (int)$sub['ended_at'] + $d;
        if (!$end && !empty($sub['current_start'])) $end = (int)$sub['current_start'] + $d;
        if (!$end && !empty($sub['created_at']))    $end = (int)$sub['created_at'] + $d;
        if ($end > time()) { $out['grant'] = true; $out['why'] = "paid period still running (status $status)"; }
        else $out['why'] = "paid period over (status $status)";
    } else {
        $out['why'] = "not paid (status $status, paid_count $paid)";
    }
    $out['end'] = $end;
    return $out;
}

function fg_json($code, $arr) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    echo json_encode($arr);
    exit;
}
function fg_preflight() {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        http_response_code(204);
        exit;
    }
}
function fg_body() {
    $j = json_decode(file_get_contents('php://input'), true);
    return is_array($j) ? $j : [];
}
