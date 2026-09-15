<?php
/**
 * Answer Writing evaluator. GET ?token=<aw_worker_token>[&one=<sha1email>/<date>]
 * Evaluates pending submissions with Gemini (key = fg-secret 'gemini_api_key'):
 *   1. transcribes the handwritten/typed answer (photos or PDF)
 *   2. counts words (server-side, deterministic)
 *   3. marks the answer out of 10 per aw-rules.php, applies the word rule
 * Result is stored in the submission meta; the app only sees it at readyAt.
 * Called by aw-submit (right after upload) and aw-status (safety net). Can also
 * be hit by a cron every 5 minutes without &one to sweep everything.
 */
require __DIR__ . '/aw-lib.php';
set_time_limit(280);
$sec = fg_secrets();
$tok = $sec['aw_worker_token'] ?? '';
if (!$tok || !hash_equals($tok, (string)($_GET['token'] ?? ''))) { http_response_code(403); exit('forbidden'); }
$KEY = $sec['gemini_api_key'] ?? '';
if (!$KEY) { http_response_code(500); exit('gemini_api_key missing in fg-secret.php'); }
// Let the caller go (submit/status use a 2 s timeout) and keep working.
ignore_user_abort(true);
header('Content-Type: text/plain'); echo "ok\n"; @ob_end_flush(); @flush();

$rules = aw_rules();
$jobs = [];
if (!empty($_GET['one']) && preg_match('#^([a-f0-9]{40})/(\d{4}-\d{2}-\d{2})$#', $_GET['one'], $m)) {
    $jobs[] = FG_DATA_DIR . '/aw/' . $m[1] . '/' . $m[2] . '.json';
} else {
    foreach (glob(FG_DATA_DIR . '/aw/*/*.json') ?: [] as $p) $jobs[] = $p;
    sort($jobs);
}

$done = 0;
foreach ($jobs as $p) {
    if ($done >= 4) break;
    if (!is_readable($p)) continue;
    $meta = json_decode(file_get_contents($p), true);
    if (!is_array($meta)) continue;
    $status = $meta['status'] ?? '';
    if ($status === 'evaluated') continue;
    if ($status === 'error' && (int)($meta['attempts'] ?? 0) >= 3) continue;
    $lock = substr($p, 0, -5) . '.lock';
    if (is_file($lock) && (time() - filemtime($lock)) < 240) continue;
    touch($lock);
    $meta['attempts'] = (int)($meta['attempts'] ?? 0) + 1;
    try {
        $meta['result'] = aw_evaluate($meta, dirname($p), $KEY, $rules);
        $meta['status'] = 'evaluated';
        $meta['evaluated_at'] = date('c');
        unset($meta['error']);
    } catch (Exception $e) {
        $meta['status'] = 'error';
        $meta['error'] = $e->getMessage();
    }
    file_put_contents($p, json_encode($meta, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @unlink($lock);
    $done++;
}
exit;

// ---------------------------------------------------------------------------
function aw_gemini($KEY, $parts, $temp, $json = true) {
    $models = ['gemini-flash-latest', 'gemini-2.5-flash', 'gemini-flash-lite-latest', 'gemini-2.5-flash-lite'];
    $last = '';
    foreach ($models as $model) {
        for ($try = 0; $try < 2; $try++) {
            $body = ['contents' => [['parts' => $parts]], 'generationConfig' => ['temperature' => $temp, 'maxOutputTokens' => 8192]];
            if ($json) $body['generationConfig']['responseMimeType'] = 'application/json';
            $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . rawurlencode($KEY));
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($body)]);
            $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
            if ($code === 429) { $last = 'rate limited'; sleep(8); continue; }
            if ($code === 404) { $last = "model $model unavailable"; break; }
            if ($code < 200 || $code >= 300) { $j = json_decode((string)$resp, true); $last = $j['error']['message'] ?? ($err ?: "HTTP $code"); break; }
            $j = json_decode((string)$resp, true);
            $text = '';
            foreach ($j['candidates'][0]['content']['parts'] ?? [] as $pt) if (empty($pt['thought'])) $text .= $pt['text'] ?? '';
            if (trim($text) === '') { $last = 'empty response'; continue; }
            return $text;
        }
    }
    throw new Exception('Gemini: ' . $last);
}
function aw_json($text) {
    $t = preg_replace('/```json\s*|```/i', '', $text);
    $s = strpos($t, '{'); $e = strrpos($t, '}');
    if ($s === false || $e === false) throw new Exception('no JSON in model output');
    $j = json_decode(substr($t, $s, $e - $s + 1), true);
    if (!is_array($j)) throw new Exception('bad JSON from model');
    return $j;
}
function aw_word_count($s) {
    $s = preg_replace('/\[illegible\]/i', ' ', $s);
    $w = preg_split('/\s+/u', trim($s));
    $n = 0; foreach ($w as $x) if (preg_match('/[\p{L}\p{N}]/u', $x)) $n++;
    return $n;
}
function aw_evaluate($meta, $dir, $KEY, $rules) {
    $fileParts = [];
    foreach ($meta['files'] as $f) {
        $bin = @file_get_contents($dir . '/' . $f['file']);
        if ($bin === false) throw new Exception('file missing ' . $f['file']);
        $fileParts[] = ['inline_data' => ['mime_type' => $f['type'], 'data' => base64_encode($bin)]];
    }
    // 1) transcript
    $tPrompt = "You are a precise OCR transcriber. The attached image(s)/PDF contain a student's handwritten or typed exam answer, possibly across several pages. Transcribe the FULL answer as plain text exactly as written: keep the order, paragraphs, headings, numbered or bulleted points. Write [illegible] for any word you cannot read. Describe any diagram or flowchart in one line inside square brackets, e.g. [Diagram: flowchart of ...]. Output ONLY the transcript, nothing else.";
    $transcript = trim(aw_gemini($KEY, array_merge([['text' => $tPrompt]], $fileParts), 0.0, false));
    if (mb_strlen(preg_replace('/\[illegible\]/i', '', $transcript)) < 40) throw new Exception('could not read the answer');
    $words = aw_word_count($transcript);

    // 2) marking
    $max = (int)$rules['max_marks'];
    $ePrompt = $rules['examiner_brief'] . "\n\nQUESTION (" . ($meta['gs'] ?: 'GS') . ", $max marks, {$rules['words_min']}-{$rules['words_max']} words):\n" . $meta['question']
        . "\n\nSTUDENT'S ANSWER (transcribed; the original pages are attached so you can also judge presentation, underlining and diagrams):\n" . $transcript
        . "\n\nReturn ONLY this JSON:\n{\"marks\": number out of $max in steps of 0.5, \"verdict\": \"Excellent | Good | Average | Needs Improvement\", \"demand_met\": \"one line: did it answer what was asked\", \"strengths\": [\"3 specific points\"], \"improvements\": [\"3 specific, actionable points\"], \"missed_points\": [\"key content the answer should have had\"], \"model_outline\": [\"8-10 crisp points an ideal answer would contain: intro, body points, conclusion\"], \"presentation\": \"one or two lines on handwriting, structure, underlining, diagrams\", \"examiner_remark\": \"2-3 sentence overall remark in the voice of an examiner\"}";
    $j = aw_json(aw_gemini($KEY, array_merge([['text' => $ePrompt]], $fileParts), 0.2, true));
    $raw = round(((float)($j['marks'] ?? 0)) * 2) / 2;
    $raw = max(0, min($max, $raw));

    // 3) rules (word limit)
    $deductions = []; $notes = [];
    if ($words > (int)$rules['words_max']) {
        $pen = (float)$rules['over_penalty'];
        if ($pen > 0) $deductions[] = ['rule' => 'word_limit', 'marks' => $pen, 'text' => "Word limit exceeded: $words words against the {$rules['words_min']}-{$rules['words_max']} limit. $pen mark deducted."];
        else $notes[] = "Word limit exceeded: $words words against the {$rules['words_min']}-{$rules['words_max']} limit.";
    } elseif ($words < (int)$rules['words_min']) {
        $pen = (float)$rules['under_penalty'];
        if ($pen > 0) $deductions[] = ['rule' => 'word_limit', 'marks' => $pen, 'text' => "Answer is short: $words words; the expected length is {$rules['words_min']}-{$rules['words_max']} words. $pen mark deducted."];
        else $notes[] = "Answer is short: $words words; the expected length is {$rules['words_min']}-{$rules['words_max']} words. Add one more dimension or example to reach the mark.";
    } else {
        $notes[] = "Word count $words: within the {$rules['words_min']}-{$rules['words_max']} limit.";
    }
    $ded = 0; foreach ($deductions as $d) $ded += $d['marks'];
    $final = max(0, $raw - $ded);
    $ratio = $max ? $final / $max : 0;
    $verdict = $ratio >= 0.75 ? 'Excellent' : ($ratio >= 0.6 ? 'Good' : ($ratio >= 0.4 ? 'Average' : 'Needs Improvement'));
    $arr = function ($v) { return is_array($v) ? array_values(array_filter(array_map('strval', $v), 'strlen')) : []; };
    return [
        'marks' => $final, 'maxMarks' => $max, 'rawMarks' => $raw,
        'deductions' => $deductions, 'notes' => $notes,
        'wordCount' => $words, 'wordsMin' => (int)$rules['words_min'], 'wordsMax' => (int)$rules['words_max'],
        'verdict' => $verdict,
        'demandMet' => (string)($j['demand_met'] ?? ''),
        'strengths' => $arr($j['strengths'] ?? []),
        'improvements' => $arr($j['improvements'] ?? []),
        'missedPoints' => $arr($j['missed_points'] ?? []),
        'modelOutline' => $arr($j['model_outline'] ?? []),
        'presentation' => (string)($j['presentation'] ?? ''),
        'examinerRemark' => (string)($j['examiner_remark'] ?? ''),
        'transcript' => $transcript,
    ];
}
