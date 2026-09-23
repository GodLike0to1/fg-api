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

// Until when has this subscription actually been PAID? (epoch; 0 = never paid)
// Razorpay moves current_start/current_end to the next month around the due date,
// while the UPI AutoPay debit for that month may still be pending or may fail. A
// cycle therefore counts only once paid_count covers it. NO grace: an unpaid
// renewal ends access exactly when the last paid month ends (= current_start).
function fg_sub_paid_through($s) {
    $paid = (int)($s['paid_count'] ?? 0);
    if ($paid < 1) return 0;
    $cs = (int)($s['current_start'] ?? 0); $ce = (int)($s['current_end'] ?? 0);
    $start = (int)($s['start_at'] ?? 0);
    if (!$cs || !$ce) {                                   // no cycle data: count paid months from the start
        $base = $start ?: (int)($s['created_at'] ?? 0);
        return $base ? $base + $paid * 30 * 86400 : 0;
    }
    if (!$start || $start > $cs) $start = $cs;
    $cycles = 1 + (int)round(($cs - $start) / (30.44 * 86400)); // billing cycles begun so far
    return $paid >= $cycles ? $ce : $cs;                   // running cycle paid -> its end; else the last paid end
}
// Read every subscription of this student from Razorpay and work out what is paid.
//  - finds subscriptions by the id on file AND by notes.email (a student can have several)
//  - main tier (MCQ Premium): the latest paid-through date of any of them (₹999 includes MCQ)
//  - Answer Writing tier: the latest paid-through date of the ₹999 ones
//  - verified=false when Razorpay could not be read (callers then keep the last known dates)
// Returns ['verified','grant','end','sub','sub_id','aw_grant','aw_end','aw_sub_id','why'].
function fg_resolve_paid_access($email, $user) {
    $sec = fg_secrets();
    $key = $sec['razorpay_key_id'] ?? ''; $secret = $sec['razorpay_key_secret'] ?? '';
    $out = ['verified' => false, 'grant' => false, 'end' => 0, 'sub' => null, 'sub_id' => null,
            'aw_grant' => false, 'aw_end' => 0, 'aw_sub_id' => null, 'why' => ''];
    if (!$key || !$secret) { $out['why'] = 'no razorpay keys'; return $out; }
    $get = function ($path) use ($key, $secret) {
        $ch = curl_init('https://api.razorpay.com/v1' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $key.':'.$secret, CURLOPT_TIMEOUT => 20]);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $r = json_decode((string)$raw, true);
        return [$code, is_array($r) ? $r : []];
    };
    $email = strtolower(trim($email));
    $cands = [];
    foreach (array_unique(array_filter([$user['subscription_id'] ?? null, $user['pending_subscription'] ?? null, $user['aw_subscription_id'] ?? null])) as $sid) {
        [$c0, $d0] = $get('/subscriptions/' . rawurlencode($sid));
        if ($c0 === 200 && !empty($d0['id'])) { $cands[$d0['id']] = $d0; $out['verified'] = true; }
    }
    for ($skip = 0; $skip < 500; $skip += 100) {            // newest first, 100 per page
        [$c1, $list] = $get('/subscriptions?count=100&skip=' . $skip);
        if ($c1 !== 200 || !isset($list['items'])) { if ($skip === 0) $out['verified'] = false; break; }
        $out['verified'] = true;
        foreach ($list['items'] as $it) {
            if (strtolower(trim($it['notes']['email'] ?? '')) === $email && !empty($it['id'])) $cands[$it['id']] = $it;
        }
        if (count($list['items']) < 100) break;
    }
    if (!$out['verified']) { $out['why'] = 'razorpay unreachable'; return $out; }
    if (!$cands) { $out['why'] = 'no subscription for this email'; return $out; }
    $awPlan = is_readable(__DIR__ . '/data/plan_aw_id.txt') ? trim(file_get_contents(__DIR__ . '/data/plan_aw_id.txt')) : '';
    // Best subscription: latest paid-through date, then a live one, then the newest.
    $rows = array_values($cands);
    usort($rows, function ($a, $b) {
        $ta = fg_sub_paid_through($a); $tb = fg_sub_paid_through($b);
        if ($ta !== $tb) return $tb <=> $ta;
        $la = in_array($a['status'] ?? '', ['active', 'authenticated'], true) ? 1 : 0;
        $lb = in_array($b['status'] ?? '', ['active', 'authenticated'], true) ? 1 : 0;
        if ($la !== $lb) return $lb <=> $la;
        return (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0);
    });
    $bestSub = $rows[0];
    foreach ($rows as $it) {
        $isAw = (($it['notes']['product'] ?? '') === 'flashgenius_aw') || ($awPlan && ($it['plan_id'] ?? '') === $awPlan);
        $t = fg_sub_paid_through($it);
        if ($isAw && $t > $out['aw_end']) { $out['aw_end'] = $t; $out['aw_sub_id'] = $it['id']; }
    }
    $end = fg_sub_paid_through($bestSub);
    $out['sub'] = $bestSub; $out['sub_id'] = $bestSub['id'] ?? null; $out['end'] = $end;
    $out['grant'] = $end > time();
    $out['aw_grant'] = $out['aw_end'] > time();
    $st = $bestSub['status'] ?? '';
    $out['why'] = $end ? ('paid through ' . date('Y-m-d H:i', $end) . " (status $st)") : "never paid (status $st)";
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
