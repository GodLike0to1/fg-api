<?php
/**
 * Paid access: the single source of truth for Premium and Answer Writing.
 *
 * Premium is granted ONLY for time that has actually been paid for. No grace days:
 *  - ₹199 monthly subscription: until the end of the last PAID billing cycle.
 *    A renewal that is still pending, failed, halted or cancelled ends access
 *    exactly when the paid month ends (see fg_sub_paid_through).
 *  - One-time passes (₹799 / ₹1,999): the fixed date that was bought.
 *  - ₹999 Answer Writing: same rule as the monthly subscription (includes MCQ Premium).
 *  - Referral trial: its own trial_until (a reward, not a payment).
 * Paid-through dates cached on the student record are trusted for at most
 * 10 minutes; once a cached date has passed it is always re-checked with Razorpay.
 *
 * Fields on the fg user record: sub_until, aw_until, pass_until, pass_plan,
 * sub_checked, subscription_id, aw_subscription_id, sub_status, sub_customer,
 * premium_until (= latest of the paid dates, kept for older readers).
 */
require_once __DIR__ . '/fg-config.php';
require_once __DIR__ . '/onetime-lib.php';
require_once __DIR__ . '/ref-lib.php';

function fg_entitlement($email, &$user) {
    $now = time();
    $email = strtolower(trim($email));
    $ts = function ($k) use (&$user) { return !empty($user[$k]) ? (int)strtotime((string)$user[$k]) : 0; };

    // Older records kept a one-time pass only in premium_until.
    if (!$ts('pass_until') && ($user['source'] ?? '') === 'razorpay_onetime' && $ts('premium_until')) {
        $user['pass_until'] = $user['premium_until'];
    }

    // 1. One-time pass paid, but the checkout never came back to verify-payment.php.
    if ($ts('pass_until') <= $now && !empty($user['pending_order']) && $now - $ts('pending_order_at') < 3 * 86400) {
        fg_onetime_resolve($email, $user);
    }

    // 2. Subscriptions: exact paid-through dates from Razorpay.
    $verified = true;
    $cachedPaid = max($ts('sub_until'), $ts('aw_until')) > $now;
    $ago = $now - $ts('sub_checked');
    if ($ago >= ($cachedPaid ? 600 : 15)) {
        $r = fg_resolve_paid_access($email, $user);
        if (!empty($r['verified'])) {
            $user['sub_checked'] = date('c', $now);
            $user['sub_until'] = $r['end'] ? date('c', (int)$r['end']) : null;
            $user['aw_until'] = $r['aw_end'] ? date('c', (int)$r['aw_end']) : null;
            if (!empty($r['sub_id']) && (int)$r['end'] > 0) $user['subscription_id'] = $r['sub_id'];
            if (!empty($r['aw_sub_id'])) $user['aw_subscription_id'] = $r['aw_sub_id'];
            if (!empty($r['sub'])) {
                $user['sub_status'] = $r['sub']['status'] ?? null;
                $user['sub_customer'] = $r['sub']['customer_id'] ?? null;
                if (($r['sub']['status'] ?? '') === 'cancelled' && empty($user['cancelled'])) $user['cancelled'] = date('c');
            }
            if ((int)$r['end'] > $now) unset($user['pending_subscription']);
        } else {
            $verified = false; // Razorpay could not be read: the last known paid dates stay as they are
        }
    }

    $subUntil = $ts('sub_until'); $awUntil = $ts('aw_until'); $passUntil = $ts('pass_until');
    // A date set by hand (any source other than our payment flows) is honoured as given.
    $manual = in_array($user['source'] ?? '', ['razorpay_web', 'razorpay_onetime', 'trial', ''], true) ? 0 : $ts('premium_until');
    $paidUntil = max($subUntil, $awUntil, $passUntil, $manual);
    $user['premium_until'] = $paidUntil ? date('c', $paidUntil) : null;
    $premium = $paidUntil > $now;
    $isPass = $premium && $passUntil === $paidUntil;
    $plan = $premium ? ($isPass ? ($user['pass_plan'] ?? (in_array($user['plan'] ?? '', ['quarter', 'annual'], true) ? $user['plan'] : 'quarter')) : 'monthly') : null;

    $trial = false; $until = $paidUntil;
    if (!$premium && ref_trial_active($user)) { $premium = true; $trial = true; $until = $ts('trial_until'); }

    return [
        'premium' => $premium,
        'premiumUntil' => $premium ? date('c', $until) : null,
        'aw' => $awUntil > $now,
        'awUntil' => $awUntil > $now ? date('c', $awUntil) : null,
        'plan' => $trial ? 'trial' : $plan,
        'trial' => $trial,
        'trialUntil' => $user['trial_until'] ?? null,
        'source' => $trial ? 'trial' : ($premium ? ($isPass ? 'razorpay_onetime' : 'razorpay_web') : null),
        'subscriptionId' => $user['subscription_id'] ?? null,
        'verified' => $verified,
        '_newSub' => $subUntil > $now && empty($user['owner_notified']),
    ];
}
