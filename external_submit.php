<?php
require_once(__DIR__ . '/../../config.php');

$token = required_param('token', PARAM_ALPHANUMEXT);

// Find profile
$profile = $DB->get_record('readingassessment_ext_prof', ['token' => $token]);
if (!$profile) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

$level_idx = optional_param('level_idx', 0, PARAM_INT);

// Check if already submitted for THIS level
$existing = $DB->get_record('readingassessment_ext_att', ['profileid' => $profile->id, 'level_idx' => $level_idx]);


// Get passage and questions to evaluate comprehension
$passage_record = $DB->get_record('readingassessment_ext_pass', ['grade_level' => $profile->grade_level]);
$custom_questions = [];
if ($passage_record && !empty($passage_record->questions_json)) {
    $all_questions = json_decode($passage_record->questions_json, true) ?: [];
    // Only keep questions for this level
    foreach ($all_questions as $q) {
        $q_level = isset($q['level_idx']) ? (int)$q['level_idx'] : 0;
        if ($q_level === $level_idx) {
            $custom_questions[] = $q;
        }
    }
}

$transcript    = optional_param('transcript', '', PARAM_RAW);
$accuracy      = optional_param('accuracy_score', 0.0, PARAM_FLOAT);
$reading_time  = optional_param('reading_time', 0, PARAM_INT);
$reading_speed = optional_param('reading_speed', 0.0, PARAM_FLOAT);
$miscues       = optional_param('miscues_json', '[]', PARAM_RAW);
$answers_raw   = optional_param('answers_json', '[]', PARAM_RAW);
$evaluation_data = optional_param('evaluation_data', '{}', PARAM_RAW);

$student_answers = json_decode($answers_raw, true) ?: [];

$total_earned = 0;
$total_max    = 0;

$requires_manual = false;
foreach ($custom_questions as $qidx => $q) {
    $qtype = $q['type'] ?? 'multichoice';
    
    if ($qtype === 'description') {
        continue;
    }
    if ($qtype === 'shortanswer' || $qtype === 'essay') {
        continue;
    }

    $ans = $student_answers[$qidx] ?? null;

    if ($qtype === 'multichoice') {
        $total_max += 1.0;
        if ($ans !== null && intval($ans) === intval($q['correct'] ?? 0)) {
            $total_earned += 1.0;
        }
    } else if ($qtype === 'truefalse') {
        $total_max += 1.0;
        $expected = ($q['correct'] === true || $q['correct'] === 'true' || $q['correct'] === 1);
        $actual   = ($ans === true || $ans === 'true' || $ans === 1 || strtolower($ans) === 'true');
        if ($ans !== null && $expected === $actual) {
            $total_earned += 1.0;
        }
    } else if ($qtype === 'enumeration') {
        $total_max += 1.0;
        $valid_answers = array_filter(array_map('trim', array_map('strtolower', explode(',', $q['answers'] ?? ''))));
        $count = intval($q['count'] ?? 3);
        if ($count <= 0) $count = 3;
        
        $user_ans_str = $ans ?? '';
        $user_ans_arr = array_map('trim', explode('|', $user_ans_str));
        $qCorrect = 0;
        foreach ($user_ans_arr as $u) {
            $u = strtolower($u);
            if ($u !== '' && in_array($u, $valid_answers)) {
                $qCorrect++;
                $idx = array_search($u, $valid_answers);
                if ($idx !== false) unset($valid_answers[$idx]);
            }
        }
        if ($qCorrect >= $count) {
            $total_earned += 1.0;
        } else {
            $total_earned += ($qCorrect / $count);
        }
    } else if ($qtype === 'matching') {
        $pairs = $q['pairs'] ?? [];
        foreach ($pairs as $pidx => $p) {
            $total_max += 1.0; 
            $expected_ans = trim(strtolower($p['answer'] ?? ''));
            $student_val = $ans[$pidx] ?? '';
            $actual_ans = trim(strtolower($student_val));
            if ($expected_ans !== '' && $expected_ans === $actual_ans) {
                $total_earned += 1.0;
            }
        }
    }
}

$comprehension = ($total_max > 0) ? round(($total_earned / $total_max) * 100.0, 2) : 100.0;

// ARAL Classification Logic (Phil-IRI standard)
$classification = 'Pending';
$words_attempted = optional_param('total_miscues', 0, PARAM_INT);

$current_passage_text = '';
if ($level_idx === 0) $current_passage_text = $passage_record->passage;
else if ($level_idx === 1) $current_passage_text = $passage_record->passage_2;
else if ($level_idx === 2) $current_passage_text = $passage_record->passage_3;
else if ($level_idx === 3) $current_passage_text = $passage_record->passage_4;

$passage_words = count(preg_split('/\s+/', preg_replace('/[.,\/#!$%\^&\*;:{}=\-_`~()"\'?]/', '', strtolower(trim($current_passage_text)))));
if ($passage_words == 0) $passage_words = 1;

if ($level_idx === 3) {
    // LEVEL 4: Listening Comprehension Test
    $word_reading_score = 0;
    $reading_rate = 0;
    
    if ($requires_manual) {
        $classification = 'Pending (Manual Grade)';
    } else {
        if ($comprehension >= 80) {
            $classification = 'Listening: Independent';
        } else if ($comprehension >= 59) {
            $classification = 'Listening: Instructional';
        } else {
            $classification = 'Listening: Frustration';
        }
    }
} else {
    // Normal Reading Test
    $word_reading_score = optional_param('accuracy_score', 0.0, PARAM_FLOAT);
    $reading_rate = optional_param('reading_speed', 0.0, PARAM_FLOAT);
    
    // Fallback if not passed
    if ($word_reading_score === 0.0) {
        $word_reading_score = round(max(0, (($passage_words - $words_attempted) / $passage_words) * 100), 2);
    }
    if ($reading_rate === 0.0) {
        $reading_rate = ($reading_time > 0) ? round(($passage_words / $reading_time) * 60) : 0;
    }

    if ($requires_manual) {
        $classification = 'Pending (Manual Grade)';
    } else {
        if ($word_reading_score < 40 || ($reading_time < 5 && $word_reading_score < 70)) {
            $classification = 'Non-Reader';
        } else if ($word_reading_score >= 97 && $comprehension >= 80) {
            $classification = 'Independent';
        } else if ($word_reading_score >= 90 && $comprehension >= 59) {
            $classification = 'Instructional';
        } else {
            $classification = 'Frustration';
            if ($level_idx === 2) $classification = 'Non-Reader';
        }
    }
}

$attempt = new stdClass();
$attempt->profileid = $profile->id;
$attempt->level_idx = $level_idx;
$attempt->transcript = $transcript;
$attempt->accuracy_score = $accuracy;
$attempt->comprehension_score = $comprehension;
$attempt->reading_time = $reading_time;
$attempt->reading_speed = $reading_speed;
$attempt->miscues_json = $miscues;
$attempt->answers_json = $answers_raw;
$attempt->evaluation_data = $evaluation_data;
$attempt->classification = $classification;
$attempt->timecompleted = time();
$attempt->philiri_json = optional_param('philiri_json', '', PARAM_RAW);

if ($existing) {
    $attempt->id = $existing->id;
    $DB->update_record('readingassessment_ext_att', $attempt);
    $attempt_id = $attempt->id;
} else {
    $attempt_id = $DB->insert_record('readingassessment_ext_att', $attempt);
}

// Calculate overall classification based on which level they just took!
$overall_classification = $classification;
if ($level_idx === 1) {
    if (in_array($classification, ['Independent', 'Instructional'])) {
        $overall_classification = 'Instructional';
    } else {
        $overall_classification = 'Frustration';
    }
} else if ($level_idx === 2) {
    if (in_array($classification, ['Independent', 'Instructional'])) {
        $overall_classification = 'Frustration';
    } else {
        $overall_classification = 'Non-Reader';
    }
} else if ($level_idx === 3) {
    // Listening comprehension doesn't change their reading classification, but we can set it if we want.
    $overall_classification = $classification;
}

// Update profile classification and masterlist status
$DB->set_field('readingassessment_ext_prof', 'classification', $overall_classification, ['id' => $profile->id]);
if (in_array($overall_classification, ['Instructional', 'Frustration', 'Non-Reader', 'Nonreader'])) {
    $DB->set_field('readingassessment_ext_prof', 'in_masterlist', 1, ['id' => $profile->id]);
} else {
    $DB->set_field('readingassessment_ext_prof', 'in_masterlist', 0, ['id' => $profile->id]);
}


// Save Audio if present
if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
    $audio_dir = $CFG->dataroot . '/readingassessment_audio';
    if (!file_exists($audio_dir)) {
        mkdir($audio_dir, 0777, true);
    }
    $ext = pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION);
    if (!$ext) $ext = 'webm';
    $dest = $audio_dir . '/attempt_' . $attempt_id . '.' . $ext;
    move_uploaded_file($_FILES['audio_file']['tmp_name'], $dest);
    $DB->set_field('readingassessment_ext_att', 'audio_path', $dest, ['id' => $attempt_id]);
}

echo json_encode([
    'status' => 'success',
    'classification' => $classification,
    'word_reading_score' => $word_reading_score,
    'comprehension_score' => $comprehension,
    'reading_rate' => $reading_rate
]);
