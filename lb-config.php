<?php
/**
 * Leaderboard settings. Edit here; no app update needed.
 */
return [
    'marks_correct' => 2.0,
    'marks_wrong'   => -0.66,
    'marks_skip'    => 0.0,
    // 26 Sep 2026: 0 = every first attempt counts, however fast. The old 4 s rule kept
    // quick first attempts off the board and locked the set; negative marking already
    // makes random tapping worthless (expected score ~0).
    'min_seconds_per_question' => 0,
    'show_count_from' => 50,           // student count is printed only once the board has this many
    'top_n' => 50,
    // Rows for these accounts are pinned as "Mentor" and never ranked.
    'mentor_emails' => ['netmockias@gmail.com', 'netmockprep@gmail.com'],
];
