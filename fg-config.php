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
