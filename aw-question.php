<?php
/**
 * GET → today's Answer Writing question (IST). Questions live in
 * aw-questions.json next to this file: [{"date":"2026-09-16","q":"...","gs":"GS2","hint":"..."}]
 * Days without an entry fall back to the last question before that date.
 */
require __DIR__ . '/aw-lib.php';
fg_preflight();
$today = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : aw_today();
$bank = json_decode((string)@file_get_contents(__DIR__ . '/aw-questions.json'), true);
$pick = null;
if (is_array($bank)) {
    foreach ($bank as $q) {
        if (empty($q['date']) || empty($q['q'])) continue;
        if ($q['date'] === $today) { $pick = $q; break; }
        if ($q['date'] < $today && (!$pick || $q['date'] > $pick['date'])) $pick = $q;
    }
}
if (!$pick) $pick = ['date' => $today, 'q' => 'Discuss the significance of the Directive Principles of State Policy in shaping welfare legislation in India.', 'gs' => 'GS2'];
$rules = aw_rules();
fg_json(200, [
    'date' => $today,
    'question' => $pick['q'],
    'gs' => $pick['gs'] ?? '',
    'hint' => $pick['hint'] ?? '',
    'wordsMin' => $rules['words_min'], 'wordsMax' => $rules['words_max'], 'maxMarks' => $rules['max_marks'],
    'readyAfterMinutes' => $rules['ready_after_minutes'],
]);
