<?php
/**
 * post_submit.php — JSON endpoint for submitting a post-test attempt.
 *
 * Receives a multipart POST from post_recorder.js AMD module.
 * Saves: attempt metadata, audio file, questionnaire responses, reading scores.
 * Returns JSON: { status, attemptId, mispronounced_words[], accuracy, wpm, comprehension }
 *
 * This file is completely separate from external_submit.php (pre-assessment).
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

header('Content-Type: application/json');

// -------------------------------------------------------------------------
// Basic validation
// -------------------------------------------------------------------------
try {
    require_sesskey();
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid session key.']);
    exit;
}

if (!isloggedin() || isguestuser()) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in.']);
    exit;
}

$cmid = required_param('cmid', PARAM_INT);

$cm      = get_coursemodule_from_id('readingassessment', $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cmid);

require_login($course, true, $cm);
require_capability('mod/readingassessment:submit', $context);

// -------------------------------------------------------------------------
// Load activity configuration
// -------------------------------------------------------------------------
$cfg = $DB->get_record('readingassessment_post_cfg', ['cmid' => $cmid]);
if (!$cfg) {
    echo json_encode(['status' => 'error', 'message' => 'Activity not configured. Please ask your teacher to set up this assessment.']);
    exit;
}

// -------------------------------------------------------------------------
// Receive payload from AMD module
// -------------------------------------------------------------------------
$assessment_json = optional_param('assessment_json', '{}', PARAM_RAW);
$data_json       = optional_param('data_json', '{}', PARAM_RAW);
$answers_raw     = optional_param('answers_json', '{}', PARAM_RAW);
$reading_time_ms = optional_param('reading_time_ms', 0, PARAM_INT);
$accuracy_score  = optional_param('accuracy_score', 0.0, PARAM_FLOAT);
$wpm             = optional_param('wpm', 0.0, PARAM_FLOAT);
$markup_html     = optional_param('markup_html', '', PARAM_RAW);
$transcript_raw  = optional_param('transcript_raw', '', PARAM_TEXT);

// Parse the assessment and data objects
$assessment = @json_decode($assessment_json, true) ?: [];
$data       = @json_decode($data_json, true) ?: [];
$answers    = @json_decode($answers_raw, true) ?: [];

// -------------------------------------------------------------------------
// Compute reading accuracy from Azure data if not sent by client
// -------------------------------------------------------------------------
if ($accuracy_score <= 0 && !empty($data['azure']['words'])) {
    $scores = array_column($data['azure']['words'], 'accuracyScore');
    $scores = array_filter($scores, fn($s) => $s > 0);
    $accuracy_score = count($scores) > 0 ? round(array_sum($scores) / count($scores), 2) : 0;
}

// Compute WPM from duration if not sent
if ($wpm <= 0 && $reading_time_ms > 0) {
    $passage_words = count(preg_split('/\s+/', trim($cfg->passage_text ?? '')));
    $wpm = round(($passage_words / ($reading_time_ms / 1000)) * 60, 2);
}

// -------------------------------------------------------------------------
// Grade questionnaire responses
// -------------------------------------------------------------------------
$questions = @json_decode($cfg->questions_json ?? '[]', true) ?: [];
$comp_earned = 0;
$comp_total  = 0;
$responses   = [];

foreach ($questions as $idx => $q) {
    if (($q['type'] ?? '') === 'description') continue;

    $points = (float)($q['points'] ?? 1);
    $comp_total += $points;
    $student_answer = $answers[$idx] ?? $answers["q{$idx}"] ?? null;
    $earned = 0;

    if ($student_answer === null) {
        $responses[] = ['questionid' => $idx, 'answer' => null, 'earned' => 0, 'possible' => $points];
        continue;
    }

    switch ($q['type']) {
        case 'multiple_choice':
            // Find the correct option
            $correct_text = '';
            foreach ($q['options'] ?? [] as $opt) {
                if (is_array($opt) && !empty($opt['correct'])) {
                    $correct_text = $opt['text'] ?? '';
                    break;
                }
            }
            if (strtolower(trim($student_answer)) === strtolower(trim($correct_text))) {
                $earned = $points;
            }
            break;

        case 'true_false':
            $correct = strtolower(trim($q['answer'] ?? 'true'));
            if (strtolower(trim($student_answer)) === $correct) {
                $earned = $points;
            }
            break;

        case 'enumeration':
            // Partial credit: award points proportionally
            $expected = array_map('strtolower', array_map('trim', $q['answers'] ?? []));
            if (!empty($expected)) {
                $given = array_map('strtolower', array_map('trim', preg_split('/[\r\n,]+/', $student_answer)));
                $hits = count(array_intersect($expected, $given));
                $earned = round(($hits / count($expected)) * $points, 2);
            }
            break;

        case 'matching':
            // student_answer format: "0:leftAnswer|1:leftAnswer|..."
            $pairs     = $q['pairs'] ?? [];
            $pair_count = count($pairs);
            if ($pair_count > 0) {
                $given_map = [];
                foreach (explode('|', $student_answer) as $part) {
                    [$k, $v] = explode(':', $part, 2) + [null, null];
                    if ($k !== null) $given_map[(int)$k] = strtolower(trim($v ?? ''));
                }
                $correct_count = 0;
                foreach ($pairs as $pi => $pair) {
                    $correct_right = strtolower(trim($pair['right'] ?? ''));
                    if (isset($given_map[$pi]) && $given_map[$pi] === $correct_right) {
                        $correct_count++;
                    }
                }
                $earned = round(($correct_count / $pair_count) * $points, 2);
            }
            break;
    }

    $comp_earned += $earned;
    $responses[] = [
        'questionid' => $idx,
        'answer'     => $student_answer,
        'earned'     => $earned,
        'possible'   => $points,
    ];
}

$comprehension_pct = ($comp_total > 0) ? round(($comp_earned / $comp_total) * 100, 2) : 0;

// -------------------------------------------------------------------------
// Extract mispronounced words from Azure result
// -------------------------------------------------------------------------
$mispronounced_words = [];
$azure_words = $data['azure']['words'] ?? [];
foreach ($azure_words as $w) {
    if (isset($w['errorType']) && in_array($w['errorType'], ['Mispronunciation', 'Omission']) && isset($w['word'])) {
        $clean = preg_replace('/[^a-zA-Z\'-]/', '', $w['word']);
        if ($clean) $mispronounced_words[] = $clean;
    }
}
// Also check alignment ops from assessment
foreach ($assessment['ops'] ?? [] as $op) {
    if (($op['type'] ?? '') === 'mispronunciation' && isset($op['ref'])) {
        $clean = preg_replace('/[^a-zA-Z\'-]/', '', $op['ref']);
        if ($clean && !in_array($clean, $mispronounced_words)) {
            $mispronounced_words[] = $clean;
        }
    }
}
$mispronounced_words = array_values(array_unique($mispronounced_words));

// -------------------------------------------------------------------------
// Save attempt record
// -------------------------------------------------------------------------
$attempt = new stdClass();
$attempt->cfgid            = $cfg->id;
$attempt->userid           = $USER->id;
$attempt->transcript_raw   = $transcript_raw;
$attempt->transcript_markup = $markup_html;
$attempt->azure_json       = $data_json;
$attempt->assessment_json  = $assessment_json;
$attempt->accuracy         = $accuracy_score;
$attempt->wpm              = $wpm;
$attempt->comprehension    = $comprehension_pct;
$attempt->comp_earned      = $comp_earned;
$attempt->comp_total       = $comp_total;
$attempt->timestarted      = time() - (int)ceil($reading_time_ms / 1000);
$attempt->timecompleted    = time();

$attempt_id = $DB->insert_record('readingassessment_post_att', $attempt);

// -------------------------------------------------------------------------
// Save questionnaire responses
// -------------------------------------------------------------------------
foreach ($responses as $resp) {
    $r = new stdClass();
    $r->attid      = $attempt_id;
    $r->questionid = $resp['questionid'];
    $r->answer_json = json_encode($resp['answer']);
    $r->earned     = $resp['earned'];
    $r->possible   = $resp['possible'];
    $DB->insert_record('readingassessment_post_resp', $r);
}

// -------------------------------------------------------------------------
// Save audio file using Moodle File API
// -------------------------------------------------------------------------
$audio_file_id = 0;
if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
    $fs = get_file_storage();
    $file_record = [
        'contextid' => $context->id,
        'component' => 'mod_readingassessment',
        'filearea'  => 'post_audio',
        'itemid'    => $attempt_id,
        'filepath'  => '/',
        'filename'  => 'attempt_' . $attempt_id . '_' . $USER->id . '.webm',
        'userid'    => $USER->id,
    ];
    $stored = $fs->create_file_from_pathname($file_record, $_FILES['audio_file']['tmp_name']);
    if ($stored) {
        $audio_file_id = $attempt_id; // itemid is the attempt ID
        $DB->set_field('readingassessment_post_att', 'audio_file_id', $audio_file_id, ['id' => $attempt_id]);
    }
}

// -------------------------------------------------------------------------
// Return success
// -------------------------------------------------------------------------
echo json_encode([
    'status'              => 'success',
    'attemptId'           => $attempt_id,
    'mispronounced_words' => $mispronounced_words,
    'accuracy'            => $accuracy_score,
    'wpm'                 => $wpm,
    'comprehension'       => $comprehension_pct,
    'comp_earned'         => $comp_earned,
    'comp_total'          => $comp_total,
]);
