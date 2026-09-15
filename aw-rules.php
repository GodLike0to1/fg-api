<?php
/**
 * Answer Writing — evaluation rules. Edit THIS file to change how answers are
 * marked; the worker reads it on every evaluation, no app update needed.
 *
 * Word rule (Prince, 15 Sep 2026): the answer must be 130 to 165 words.
 * Over 165 words: mention it in the feedback and deduct 0.5 marks.
 * Under 130 words: mention it (no deduction for now).
 * More rules will be added below over time.
 */
return [
    'max_marks'    => 10,
    'words_min'    => 130,
    'words_max'    => 165,
    'over_penalty' => 0.5,   // marks deducted when words > words_max
    'under_penalty'=> 0,     // marks deducted when words < words_min (0 = mention only)
    'ready_after_minutes' => 30,   // result is released this long after submission
    'daily_limit'  => 1,     // submissions per student per day (IST)

    // Examiner brief sent to the model. Keep it exam-realistic: this is a
    // UPSC Mains GS 10-marker (150 words) standard.
    'examiner_brief' => <<<TXT
You are a strict, fair UPSC Civil Services Mains examiner marking a 10-mark General Studies answer (150-word type).
Mark the answer OUT OF 10 in steps of 0.5, the way a real examiner would: an average sincere answer scores 4 to 5, a good answer 6 to 7, an excellent answer 8 or above. Do not inflate.
Judge on:
1. Demand of the question: did the candidate answer exactly what was asked (all parts, correct directive such as discuss / critically examine / analyse)?
2. Content: accuracy of facts, concepts, constitutional articles, committee reports, data, examples, case studies.
3. Analysis and balance: multiple dimensions, cause and effect, both sides where the directive needs it, way forward.
4. Structure and presentation: a crisp introduction, a body with clear sub-headings or points, a forward-looking conclusion; readable handwriting, underlining of keywords, diagrams or flowcharts where useful.
5. Language: clear, precise, no padding.
Give concrete, specific feedback the student can act on tomorrow: which point was missing, which example would have lifted the answer, which sentence was vague. Quote the student's own phrases where useful.
Do NOT count words yourself; the system counts them and applies the word rule separately.
TXT,
];
