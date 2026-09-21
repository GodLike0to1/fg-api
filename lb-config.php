<?php
/**
 * Leaderboard settings. Edit here; no app update needed.
 */
return [
    'marks_correct' => 2.0,
    'marks_wrong'   => -0.66,
    'marks_skip'    => 0.0,
    'min_seconds_per_question' => 4,   // faster than this for the whole set = practice, not ranked
    'show_count_from' => 50,           // student count is printed only once the board has this many
    'top_n' => 50,
    // Rows for these accounts are pinned as "Mentor" and never ranked.
    'mentor_emails' => ['netmockias@gmail.com', 'netmockprep@gmail.com'],
];
