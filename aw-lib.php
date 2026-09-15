<?php
/**
 * Answer Writing — shared helpers (storage, entitlement, dates).
 * Storage: FG_DATA_DIR/aw/<sha1(email)>/<YYYY-MM-DD>.json  (meta + result)
 *          FG_DATA_DIR/aw/<sha1(email)>/<YYYY-MM-DD>-<n>.<ext>  (uploaded files)
 */
require_once __DIR__ . '/fg-config.php';

function aw_rules() { static $r = null; if ($r === null) $r = include __DIR__ . '/aw-rules.php'; return $r; }
function aw_tz() { return new DateTimeZone('Asia/Kolkata'); }
function aw_today() { return (new DateTime('now', aw_tz()))->format('Y-m-d'); }
function aw_dir($email) { return FG_DATA_DIR . '/aw/' . sha1(strtolower(trim($email))); }
function aw_meta_path($email, $date) { return aw_dir($email) . '/' . $date . '.json'; }
function aw_load($email, $date) {
    $p = aw_meta_path($email, $date);
    if (!is_readable($p)) return null;
    $j = json_decode(file_get_contents($p), true);
    return is_array($j) ? $j : null;
}
function aw_save($email, $date, $meta) {
    $d = aw_dir($email);
    if (!is_dir($d)) mkdir($d, 0755, true);
    $ht = FG_DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\n");
    $meta['updated'] = date('c');
    file_put_contents(aw_meta_path($email, $date), json_encode($meta, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// Is this student entitled to Answer Writing right now? Uses the cached
// aw_until on the user record; re-resolves from Razorpay when missing/expired
// (at most once every 30 minutes so a launch does not hammer Razorpay).
function aw_entitled($email, &$user) {
    $user = $user ?: (fg_load_user($email) ?: []);
    $until = $user['aw_until'] ?? null;
    if ($until && strtotime($until) > time()) return true;
    $checked = isset($user['aw_checked']) ? strtotime($user['aw_checked']) : 0;
    if (time() - $checked < 1800) return false;
    $r = fg_resolve_paid_access($email, $user);
    $user['aw_checked'] = date('c');
    if (!empty($r['aw_grant'])) {
        $user['aw_until'] = date('c', (int)$r['aw_end'] + 24 * 3600);
        $user['aw_subscription_id'] = $r['aw_sub_id'] ?? null;
        // ₹999 includes MCQ premium
        if (empty($user['premium_until']) || strtotime($user['premium_until']) < (int)$r['aw_end']) {
            $user['premium_until'] = $user['aw_until'];
            $user['source'] = 'razorpay_web';
        }
        fg_save_user($email, $user);
        return true;
    }
    fg_save_user($email, $user);
    return false;
}

// Public view of one submission (result hidden until ready_at).
function aw_public($meta) {
    $rules = aw_rules();
    $ready = (int)$meta['submitted_ts'] + 60 * (int)$rules['ready_after_minutes'];
    $out = [
        'date' => $meta['date'],
        'question' => $meta['question'],
        'custom' => !empty($meta['custom']),
        'submittedAt' => $meta['submitted_at'],
        'readyAt' => date('c', $ready),
        'status' => $meta['status'],   // pending | evaluated | error
        'pages' => count($meta['files'] ?? []),
    ];
    if ($meta['status'] === 'evaluated' && time() >= $ready) {
        $out['result'] = $meta['result'];
        $out['status'] = 'ready';
    } elseif ($meta['status'] === 'evaluated') {
        $out['status'] = 'pending'; // evaluated early, but the student sees it only at readyAt
    }
    if ($meta['status'] === 'error' && (int)($meta['attempts'] ?? 0) >= 3) $out['status'] = 'failed';
    elseif ($meta['status'] === 'error') $out['status'] = 'pending';
    return $out;
}
