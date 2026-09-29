<?php
require_once(__DIR__ . '/../../config.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/mod/readingassessment/external_manage.php'));

require_once($CFG->libdir . '/adminlib.php');

// For now, require site:config, but ideally this would use a module context
// if it were tied to a course. Since it's external, site:config or a specific capability is good.
require_login();
if (isguestuser()) {
    print_error('noguest');
}

// Allow if site admin or has a teacher/manager role anywhere
$is_admin = is_siteadmin();
$is_teacher = $DB->record_exists_sql("
    SELECT 1 FROM {role_assignments} ra 
    JOIN {role} r ON ra.roleid = r.id 
    WHERE ra.userid = ? AND r.shortname IN ('editingteacher', 'teacher', 'manager')
", [$USER->id]);

if (!$is_admin && !$is_teacher) {
    redirect(new moodle_url('/mod/readingassessment/external.php'));
}

$context = context_system::instance();

$action = optional_param('action', '', PARAM_TEXT);

// === AUTO UPGRADE DB SCHEMA FOR ARAL PHIL-IRI ===
require_once($CFG->libdir . '/ddllib.php');
$dbman = $DB->get_manager();

$table_prof = new xmldb_table('readingassessment_ext_prof');
$prof_fields = [
    new xmldb_field('firstname', XMLDB_TYPE_CHAR, '255', null, null, null, null),
    new xmldb_field('middleinitial', XMLDB_TYPE_CHAR, '10', null, null, null, null),
    new xmldb_field('lastname', XMLDB_TYPE_CHAR, '255', null, null, null, null),
    new xmldb_field('email', XMLDB_TYPE_CHAR, '255', null, null, null, null),
    new xmldb_field('section', XMLDB_TYPE_CHAR, '255', null, null, null, null),
    new xmldb_field('classification', XMLDB_TYPE_CHAR, '50', null, null, null, null),
    new xmldb_field('in_masterlist', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1')
];
foreach ($prof_fields as $f) { if (!$dbman->field_exists($table_prof, $f)) $dbman->add_field($table_prof, $f); }

$table_att = new xmldb_table('readingassessment_ext_att');
$att_fields = [
    new xmldb_field('audio_path', XMLDB_TYPE_CHAR, '500', null, null, null, null),
    new xmldb_field('philiri_json', XMLDB_TYPE_TEXT, null, null, null, null, null),
    new xmldb_field('reading_speed', XMLDB_TYPE_NUMBER, '10,2', null, null, null, '0.00'),
    new xmldb_field('comprehension_score', XMLDB_TYPE_NUMBER, '10,2', null, null, null, '0.00'),
    new xmldb_field('accuracy_score', XMLDB_TYPE_NUMBER, '10,2', null, null, null, '0.00'),
    new xmldb_field('level_idx', XMLDB_TYPE_INTEGER, '10', null, null, null, '0')
];
foreach ($att_fields as $f) { if (!$dbman->field_exists($table_att, $f)) $dbman->add_field($table_att, $f); }

$table_pass = new xmldb_table('readingassessment_ext_pass');
$pass_f = new xmldb_field('level_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'Independent');
if (!$dbman->field_exists($table_pass, $pass_f)) $dbman->add_field($table_pass, $pass_f);
// ================================================
// Seed exactly 4 rows (Grade 7, 8, 9, 10)
$grades_to_seed = [7, 8, 9, 10];
foreach ($grades_to_seed as $g) {
    if (!$DB->record_exists('readingassessment_ext_pass', ['grade_level' => $g])) {
        $new_pass = new stdClass();
        $new_pass->title = "Grade $g";
        $new_pass->passage = "Independent passage for Grade $g.";
        $new_pass->passage_2 = "Instructional passage for Grade $g.";
        $new_pass->passage_3 = "Frustration passage for Grade $g.";
        $new_pass->passage_4 = "Non-reader passage for Grade $g.";
        $new_pass->questions_json = "[]";
        $new_pass->sortorder = $g;
        $new_pass->timecreated = time();
        $new_pass->grade_level = $g;
        $DB->insert_record('readingassessment_ext_pass', $new_pass);
    }
}
$DB->execute("DELETE FROM {readingassessment_ext_pass} WHERE level_type = 'Instructional' OR level_type = 'Frustration'");
$DB->execute("UPDATE {readingassessment_ext_pass} SET title = CONCAT('Grade ', grade_level)");

// Handle Environment Saving
if ($action === 'save_env' && data_submitted()) {
    require_sesskey();
    $keys = ['AZURE_OPENAI_API_KEY', 'REALTIME_TRANSCRIPTION_MODEL', 'REALTIME_TRANSCRIPTION_DELAY', 'VAD_START_THRESHOLD', 'VAD_STOP_THRESHOLD', 'VAD_SILENCE_MS', 'VAD_MIN_SPEECH_MS', 'SPEECH_KEY', 'SPEECH_REGION', 'SERVICE_HOST', 'SERVICE_PORT', 'SESSION_TIMEOUT_SEC', 'SPEECH_FINALIZATION_TIMEOUT_SEC', 'FINALIZATION_TIMEOUT_MS'];
    $env_content = "# Auto-generated ENV\n";
    foreach ($keys as $k) {
        $val = optional_param($k, '', PARAM_RAW);
        if ($val !== '') $env_content .= "$k=$val\n";
    }
    file_put_contents(__DIR__ . '/v536/.env', $env_content);
    redirect($_SERVER['REQUEST_URI'], 'Settings saved successfully.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Handle Server Control
if ($action === 'server_control' && data_submitted()) {
    require_sesskey();
    $cmd = optional_param('cmd', '', PARAM_TEXT);
    $output = [];
    $v536_dir = __DIR__ . '/v536';
    if ($cmd === 'create') {
        exec("cd $v536_dir && python3 -m venv venv_asr && ./venv_asr/bin/pip install azure-cognitiveservices-speech && npm install 2>&1", $output);
    } else if ($cmd === 'start') {
        $existing = shell_exec("pgrep -f '[u]vicorn service:app'");
        if ($existing && trim($existing) !== '') {
            $output[] = "Server is already running. Action ignored.";
        } else {
            exec("cd $v536_dir && nohup ./venv_asr/bin/python3 -m uvicorn service:app --host 0.0.0.0 --port 3000 > server.log 2>&1 &");
            $output[] = "Server started in background.";
        }
    } else if ($cmd === 'stop') {
        exec("pkill -f 'uvicorn service:app' 2>&1", $output);
        sleep(1);
        $output[] = "Stopped python processes.";
    } else if ($cmd === 'restart') {
        exec("pkill -f 'uvicorn service:app'");
        sleep(1);
        exec("cd $v536_dir && nohup ./venv_asr/bin/python3 -m uvicorn service:app --host 0.0.0.0 --port 3000 > server.log 2>&1 &");
        $output[] = "Server restarted.";
    }
    $msg = implode("<br>", $output);
    redirect($_SERVER['REQUEST_URI'], "Command executed: $cmd<br>$msg", null, \core\output\notification::NOTIFY_INFO);
}


// Handle Save Markup Overrides
if ($action === 'save_markup' && data_submitted()) {
    $att_id = required_param('att_id', PARAM_INT);
    $markup = optional_param('markup', '', PARAM_RAW);
    $counts = optional_param('counts', '{}', PARAM_RAW);
    $accuracy = optional_param('accuracy', 0.0, PARAM_FLOAT);
    
    $attempt = $DB->get_record('readingassessment_ext_att', ['id' => $att_id]);
    if ($attempt) {
        $pJson = json_decode($attempt->philiri_json, true) ?: [];
        $pJson['markup'] = $markup;
        $pJson['counts'] = json_decode($counts, true);
        
        $DB->set_field('readingassessment_ext_att', 'philiri_json', json_encode($pJson), ['id' => $att_id]);
        $DB->set_field('readingassessment_ext_att', 'accuracy_score', $accuracy, ['id' => $att_id]);
        
        // Re-calculate classification based on new accuracy and old comp
        $comp = $attempt->comprehension_score;
        $class = "Frustration";
        if ($accuracy >= 97 && $comp >= 80) $class = "Independent";
        else if ($accuracy >= 90 && $comp >= 59) $class = "Instructional";
        $DB->set_field('readingassessment_ext_att', 'classification', $class, ['id' => $att_id]);
        
        // Also update the profile masterlist status
        $DB->set_field('readingassessment_ext_prof', 'classification', $class, ['id' => $attempt->profileid]);
        $in_ml = in_array($class, ['Instructional', 'Frustration', 'Nonreader', 'Non-Reader']) ? 1 : 0;
        $DB->set_field('readingassessment_ext_prof', 'in_masterlist', $in_ml, ['id' => $attempt->profileid]);
        
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// Handle Masterlist Actions
if ($action === 'masterlist_actions' && data_submitted()) {
    require_sesskey();
    $ml_action = optional_param('ml_action', '', PARAM_ALPHANUMEXT);
    $ml_ids = optional_param_array('ml_ids', [], PARAM_INT);
    
    if (empty($ml_ids)) {
        redirect($_SERVER['REQUEST_URI'], 'No students selected.', null, \core\output\notification::NOTIFY_ERROR);
    }
    
    if ($ml_action === 'remove') {
        list($in, $params) = $DB->get_in_or_equal($ml_ids);
        $DB->execute("UPDATE {readingassessment_ext_prof} SET in_masterlist = 0 WHERE id $in", $params);
        redirect($_SERVER['REQUEST_URI'], 'Students removed from masterlist.', null, \core\output\notification::NOTIFY_SUCCESS);
    } else if ($ml_action === 'create_accounts') {
        error_log("Entering create_accounts block");
        $course_id = optional_param('enroll_course_id', 0, PARAM_INT);
        if (!$course_id) {
            redirect($_SERVER['REQUEST_URI'], 'Please select a course from the dropdown to enroll students in.', null, \core\output\notification::NOTIFY_ERROR);
        }
        
        require_once($CFG->dirroot.'/user/lib.php');
        require_once($CFG->dirroot.'/enrol/locallib.php');
        
        $enrol = enrol_get_plugin('manual');
        if (!$enrol) {
            redirect($_SERVER['REQUEST_URI'], 'Manual enrollment plugin not enabled on this site.', null, \core\output\notification::NOTIFY_ERROR);
        }
        
        $instances = enrol_get_instances($course_id, true);
        $enrol_instance = null;
        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                $enrol_instance = $instance;
                break;
            }
        }
        if (!$enrol_instance) {
            redirect($_SERVER['REQUEST_URI'], 'No manual enrolment method found for the selected course.', null, \core\output\notification::NOTIFY_ERROR);
        }
        
        $student_role = $DB->get_record('role', ['shortname' => 'student']);
        if (!$student_role) {
            redirect($_SERVER['REQUEST_URI'], 'Student role not found on system.', null, \core\output\notification::NOTIFY_ERROR);
        }
        
        $created_count = 0;
        $enrolled_count = 0;
        
        list($in, $params) = $DB->get_in_or_equal($ml_ids);
        $profiles = $DB->get_records_select('readingassessment_ext_prof', "id $in", $params);
        
        foreach ($profiles as $prof) {
            $fname = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($prof->firstname));
            $mi = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($prof->middleinitial));
            $lname = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($prof->lastname));
            
            $username = $fname;
            if (!empty($mi)) $username .= '.' . $mi;
            $username .= '.' . $lname;
            
            $base_username = $username;
            $i = 1;
            while ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
                $username = $base_username . $i;
                $i++;
            }
            
            $email = $prof->email ?: ($username . '@example.com');
            if ($DB->record_exists('user', ['email' => $email])) {
                $email = $username . '@example.com';
            }
            
            $user = new stdClass();
            $user->auth = 'manual';
            $user->confirmed = 1;
            $user->mnethostid = $CFG->mnet_localhost_id;
            $user->username = $username;
            $user->password = 'Student123!';
            $user->firstname = $prof->firstname;
            $user->lastname = $prof->lastname;
            $user->email = $email;
            $user->city = 'City';
            $user->country = 'PH';
            
            try {
                // Pass true for the second argument so Moodle securely hashes the password
                $userid = user_create_user($user, true, false);
                if ($userid) {
                    $created_count++;
                    try {
                        $enrol->enrol_user($enrol_instance, $userid, $student_role->id);
                    } catch (Exception $e) {
                        // Moodle email sender might throw an exception when sending a course welcome email. We ignore it.
                        error_log("Enrollment email exception ignored: " . $e->getMessage());
                    }
                    $enrolled_count++;
                    
                    // Remove from masterlist since they are now enrolled!
                    $DB->set_field('readingassessment_ext_prof', 'in_masterlist', 0, ['id' => $prof->id]);
                }
            } catch (Exception $e) {
                error_log("Failed to create user: " . $e->getMessage());
            }
        }
        
        redirect($_SERVER['REQUEST_URI'], "Successfully created $created_count accounts and enrolled $enrolled_count students! They have been removed from the Masterlist.", null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();
echo "<link rel=\"stylesheet\" href=\"v536/public/style.css\">";

$public_url = new moodle_url('/mod/readingassessment/external.php');

// Get stats
$total_profiles = $DB->count_records('readingassessment_ext_prof');
$total_attempts = $DB->count_records('readingassessment_ext_att');

// Recent attempts

// Handle manual grading submission
$action_grade = optional_param('action', '', PARAM_ALPHA);
if ($action_grade === 'manual_grade' && data_submitted()) {
    require_sesskey();
    $att_id = required_param('att_id', PARAM_INT);
    
    $attempt = $DB->get_record('readingassessment_ext_att', ['id' => $att_id]);
    if ($attempt) {
        $answers = json_decode($attempt->answers_json, true) ?: [];
        $profile = $DB->get_record('readingassessment_ext_prof', ['id' => $attempt->profileid]);
        $passage_record = $DB->get_record('readingassessment_ext_pass', ['grade_level' => $profile->grade_level]);
        
        $total_earned = 0;
        $total_max = 0;
        
        if ($passage_record && !empty($passage_record->questions_json)) {
            $all_questions = json_decode($passage_record->questions_json, true) ?: [];
            $custom_questions = [];
            foreach ($all_questions as $q) {
                $q_level = isset($q['level_idx']) ? (int)$q['level_idx'] : 0;
                if ($q_level === (int)$attempt->level_idx) {
                    $custom_questions[] = $q;
                }
            }
            
            foreach ($custom_questions as $qidx => $q) {
                $qtype = $q['type'] ?? 'multichoice';
                if ($qtype === 'description') continue;
                
                $ans = $answers[$qidx] ?? null;
                
                if ($qtype === 'shortanswer' || $qtype === 'essay') {
                    $total_max += 1.0;
                    $manual_pts = optional_param('manual_grade_' . $qidx, 0, PARAM_FLOAT);
                    $total_earned += $manual_pts;
                } else if ($qtype === 'multichoice') {
                    $total_max += 1.0;
                    if ($ans !== null && intval($ans) === intval($q['correct'] ?? 0)) $total_earned += 1.0;
                } else if ($qtype === 'truefalse') {
                    $total_max += 1.0;
                    $expected = ($q['correct'] === true || $q['correct'] === 'true' || $q['correct'] === 1);
                    $actual   = ($ans === true || $ans === 'true' || $ans === 1 || strtolower($ans) === 'true');
                    if ($ans !== null && $expected === $actual) $total_earned += 1.0;
                } else if ($qtype === 'matching') {
                    $pairs = $q['pairs'] ?? [];
                    foreach ($pairs as $pidx => $p) {
                        $total_max += 1.0; 
                        $expected_ans = trim(strtolower($p['answer'] ?? ''));
                        $student_val = $ans[$pidx] ?? '';
                        if ($expected_ans !== '' && $expected_ans === trim(strtolower($student_val))) $total_earned += 1.0;
                    }
                }
            }
        }
        
        $comprehension = ($total_max > 0) ? round(($total_earned / $total_max) * 100.0, 2) : 100.0;
        $attempt->comprehension_score = $comprehension;
        
        $current_passage_text = '';
        if ($attempt->level_idx == 0) $current_passage_text = $passage_record->passage;
        else if ($attempt->level_idx == 1) $current_passage_text = $passage_record->passage_2;
        else if ($attempt->level_idx == 2) $current_passage_text = $passage_record->passage_3;
        else if ($attempt->level_idx == 3) $current_passage_text = $passage_record->passage_4;
        
        $passage_words = count(preg_split('/\s+/', preg_replace('/[^a-z0-9]/', ' ', strtolower(trim($current_passage_text)))));
        if ($passage_words == 0) $passage_words = 1;
        
        $miscues_arr = json_decode($attempt->miscues_json, true) ?: [];
        $words_attempted = count($miscues_arr);
        $word_reading_score = round(max(0, (($passage_words - $words_attempted) / $passage_words) * 100), 2);
        
        if ($attempt->level_idx == 3) {
            if ($comprehension >= 80) $classification = 'Listening: Independent';
            else if ($comprehension >= 59) $classification = 'Listening: Instructional';
            else $classification = 'Listening: Frustration';
        } else {
            if ($word_reading_score < 40 || ($attempt->reading_time < 5 && $word_reading_score < 70)) {
                $classification = 'Non-Reader';
            } else if ($word_reading_score >= 97 && $comprehension >= 80) {
                $classification = 'Independent';
            } else if ($word_reading_score >= 90 && $comprehension >= 59) {
                $classification = 'Instructional';
            } else {
                $classification = 'Frustration';
            }
        }
        
        $attempt->classification = $classification;
        $attempt->timecompleted = time();
        $DB->update_record('readingassessment_ext_att', $attempt);
        redirect($_SERVER['REQUEST_URI'], 'Grade saved successfully.', null, '\core\output\notification::NOTIFY_SUCCESS');
    }
}

// Get all profiles with attempts, sorted by latest activity
$sql = "SELECT p.*, MAX(a.timecompleted) as last_completed 
        FROM {readingassessment_ext_prof} p
        JOIN {readingassessment_ext_att} a ON a.profileid = p.id
        GROUP BY p.id, p.token, p.firstname, p.middleinitial, p.lastname, p.email, p.section, p.classification, p.in_masterlist, p.lrn, p.gender, p.age, p.grade_level, p.consent_agreed, p.timecreated
        ORDER BY last_completed DESC LIMIT 50";
$recent_profiles = $DB->get_records_sql($sql);


$env_content = file_exists(__DIR__ . '/v536/.env') ? file_get_contents(__DIR__ . '/v536/.env') : '';
$env_vars = [];
foreach (explode("\n", $env_content) as $line) {
    if (strpos(trim($line), '#') === 0 || empty(trim($line))) continue;
    $parts = explode('=', $line, 2);
    if (count($parts) == 2) {
        $env_vars[trim($parts[0])] = trim($parts[1]);
    }
}
?>

<div class="container-fluid mt-4">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Teacher Dashboard</h2>
        <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#envModal">
            ⚙️ API Settings
        </button>
    </div>

    <div class="row" id="dashboard-main-view">
        <!-- Sidebar / Setup -->
        <div class="col-md-4">
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">⚙️ Setup Passages</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">Configure the reading texts and questions for each grade level.</p>
                    <div class="list-group">
                        <?php 
                        $passages = $DB->get_records('readingassessment_ext_pass', null, 'sortorder ASC, id ASC');
                        foreach ($passages as $p): 
                            $disp = !empty($p->title) ? s($p->title) : "Grade " . $p->grade_level . " Passage";
                        ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?php echo $disp; ?></strong>
                                </div>
                                <div>
                                    <a href="external_edit.php?grade=<?php echo $p->id; ?>" class="btn btn-sm btn-primary ml-2">Edit ➔</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                    
<?php
$v536_dir = __DIR__ . '/v536';
$has_venv = is_dir($v536_dir . '/venv_asr');
$has_node = false; // NodeJS completely removed in refactor

// Check if Node server is running on port 3000 or by process name
$server_running = false;
$pid = shell_exec("pgrep -f '[u]vicorn service:app'");
if ($pid && trim($pid) !== '') {
    $server_running = true;
}

$status_html = '<div class="alert alert-' . ($server_running ? 'success' : 'danger') . ' py-2 mt-2 mb-0" style="font-size: 13px;">';
$status_html .= '<strong>Status: </strong>' . ($server_running ? '🟢 Running' : '🔴 Stopped');
$status_html .= '<br><small>VENV: ' . ($has_venv ? '✅ Installed' : '❌ Missing') . '</small>';
$status_html .= '</div>';
?>

                    <hr>
                    <h6 class="mt-4 font-weight-bold">🖥️ Assessment Server</h6>
                    <?php echo $status_html; ?>

                    <form method="POST" action="external_manage.php" class="mb-3">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="action" value="server_control">
                        <button type="submit" name="cmd" value="create" class="btn btn-sm btn-outline-secondary mb-1 w-100">📦 Create venv & Install</button>
                        <div class="btn-group w-100">
                            <button type="submit" name="cmd" value="start" class="btn btn-sm btn-success" <?php echo $server_running ? 'disabled' : ''; ?>>▶ Start</button>
                            <button type="submit" name="cmd" value="restart" class="btn btn-sm btn-warning" <?php echo !$server_running ? 'disabled' : ''; ?>>🔄 Restart</button>
                            <button type="submit" name="cmd" value="stop" class="btn btn-sm btn-danger" <?php echo !$server_running ? 'disabled' : ''; ?>>⏹ Stop</button>
                        </div>
                    </form>

                        <?php if (empty($passages)): ?>
                            <div class="list-group-item text-muted text-center">No passages configured yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">🔗 Public Share Link</h5>
                </div>
                <div class="card-body text-center">
                    <p class="text-muted">Distribute this link to students. No Moodle login required.</p>
                    <div class="input-group mb-3">
                        <input type="text" id="publicLink" class="form-control" value="<?php echo $public_url; ?>" readonly>
                        <div class="input-group-append">
                            <button class="btn btn-outline-secondary" type="button" onclick="copyLink()">Copy</button>
                        </div>
                    </div>
                    <div class="alert alert-info py-2">
                        <strong>Stats:</strong> <?php echo $total_profiles; ?> Profiles created, <?php echo $total_attempts; ?> Assessments completed.
                    </div>
                </div>
            </div>
        </div>

        
            
        <!-- Main Content / Results -->
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">📊 Recent Submissions</h5>
                </div>
                <div class="card-body p-0">
                                        <?php if (empty($recent_profiles)): ?>
                        <div class="p-4 text-center text-muted">No external test submissions yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Student</th>
                                        <th>Classification</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_profiles as $prof): 
                                        // Get all attempts for this profile ordered by level
                                        $attempts = $DB->get_records('readingassessment_ext_att', ['profileid' => $prof->id], 'level_idx ASC');
                                        if (empty($attempts)) continue;
                                        $last_att = end($attempts);
                                        
                                        $passage_record = $DB->get_record('readingassessment_ext_pass', ['grade_level' => $prof->grade_level]);
                                        $levelNames = [0 => 'Independent', 1 => 'Instructional', 2 => 'Frustration', 3 => 'Non-Reader'];
?>
                                        <tr>
                                            <td><?php echo userdate($last_att->timecompleted, '%b %d, %H:%M'); ?></td>
                                            <td>
                                                <strong><?php echo s($prof->lastname . ', ' . $prof->firstname . ' ' . $prof->middleinitial); ?></strong><br>
                                                <small class="text-muted">LRN: <?php echo s($prof->lrn); ?></small><br>
                                                <small class="text-muted"><?php echo s($prof->gender); ?>, <?php echo s($prof->age); ?> yrs old</small>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    if (strpos($prof->classification, 'Independent') !== false) echo 'success';
                                                    else if (strpos($prof->classification, 'Instructional') !== false) echo 'info';
                                                    else if (strpos($prof->classification, 'Frustration') !== false) echo 'warning';
                                                    else if (strpos($prof->classification, 'Non-Reader') !== false) echo 'danger';
                                                    else echo 'secondary';
                                                ?>">
                                                    <?php echo s($prof->classification ?: 'Pending'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                    $att_data = [];
                                                    for ($i=0; $i<4; $i++) {
                                                        $has_attempt = false;
                                                        $ptext = '';
                                                        if ($passage_record) {
                                                            if ($i === 0) $ptext = $passage_record->passage;
                                                            else if ($i === 1) $ptext = $passage_record->passage_2;
                                                            else if ($i === 2) $ptext = $passage_record->passage_3;
                                                            else if ($i === 3) $ptext = $passage_record->passage_4;
                                                        }
                                                        
                                                        foreach ($attempts as $a) {
                                                            if ((int)$a->level_idx === $i) {
                                                                $att_data[] = [
                                                                    'id' => $a->id,
                                                                    'level_idx' => $i,
                                                                    'test_name' => $levelNames[$i],
                                                                    'passage_text' => $ptext,
                                                                    'questions_json' => $passage_record->questions_json ?? '[]',
                                                                    'answers_json' => $a->answers_json,
                                                                    'miscues_json' => $a->miscues_json,
                                                                    'evaluation_data' => $a->evaluation_data,
                                                                    'classification' => $a->classification ?: 'Pending',
                                                                    'transcript' => '',
                                                                    'philiri_json' => $a->philiri_json,
                                                                    'wpm' => $a->reading_speed,
                                                                    'comp' => $a->comprehension_score,
                                                                    'student_name' => $prof->lastname . ', ' . $prof->firstname . ' ' . $prof->middleinitial,
                                                                    'taken' => true
                                                                ];
                                                                $has_attempt = true;
                                                                break;
                                                            }
                                                        }
                                                        
                                                        if (!$has_attempt) {
                                                            $att_data[] = [
                                                                'id' => 'none_' . $i,
                                                                'level_idx' => $i,
                                                                'test_name' => $levelNames[$i],
                                                                'passage_text' => $ptext,
                                                                'questions_json' => '[]',
                                                                'answers_json' => '{}',
                                                                'miscues_json' => '{}',
                                                                'evaluation_data' => '{}',
                                                                'classification' => 'Not Taken',
                                                                'transcript' => '',
                                                                'philiri_json' => '{}',
                                                                'wpm' => 0,
                                                                'comp' => 0,
                                                                'student_name' => $prof->lastname . ', ' . $prof->firstname . ' ' . $prof->middleinitial,
                                                                'taken' => false
                                                            ];
                                                        }
                                                    }
                                                    $att_json = json_encode($att_data);
                                                ?>
                                                <button class=\"btn btn-sm btn-outline-primary\" onclick='viewStudentAnswers(this, <?php echo $prof->id; ?>)' data-attempts='<?php echo s($att_json); ?>'>
                                                    View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card shadow-sm mt-4">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">📋 ARAL Program Masterlist</h5>
                </div>
                <div class="card-body p-3">
                    <p class="text-muted small">Students classified as Instructional, Frustration, or Nonreader for the Post-Test ARAL program.</p>
                    <form method="POST" action="external_manage.php">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="action" value="masterlist_actions">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover table-striped">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" onclick="document.querySelectorAll('.ml-chk').forEach(c => c.checked = this.checked)"></th>
                                        <th>Student</th>
                                        <th>Grade/Section</th>
                                        <th>Classification</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $masterlist = $DB->get_records_select('readingassessment_ext_prof', "in_masterlist = 1 AND classification IN ('Instructional', 'Frustration', 'Nonreader', 'Non-Reader')", null, 'lastname ASC');
                                    if (empty($masterlist)): ?>
                                        <tr><td colspan="4" class="text-center text-muted">No students in masterlist yet.</td></tr>
                                    <?php else: foreach ($masterlist as $ml): ?>
                                        <tr>
                                            <td><input type="checkbox" class="ml-chk" name="ml_ids[]" value="<?php echo $ml->id; ?>"></td>
                                            <td><strong><?php echo s($ml->lastname . ', ' . $ml->firstname); ?></strong><br><small>LRN: <?php echo s($ml->lrn); ?></small></td>
                                            <td><?php echo s($ml->grade_level); ?> / <?php echo s($ml->section); ?></td>
                                            <td><span class="badge badge-warning"><?php echo s($ml->classification); ?></span></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (!empty($masterlist)): ?>
                        <div class="mt-2 d-flex align-items-center flex-wrap gap-2">
                            <?php
                            global $USER, $DB;
                            $courses = [];
                            if (is_siteadmin()) {
                                $courses = $DB->get_records_select('course', 'id != ?', [SITEID], 'fullname ASC', 'id, fullname');
                            } else {
                                $sql = "SELECT c.id, c.fullname
                                        FROM {course} c
                                        JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
                                        JOIN {role_assignments} ra ON ra.contextid = ctx.id
                                        JOIN {role} r ON ra.roleid = r.id
                                        WHERE ra.userid = ? AND r.shortname IN ('editingteacher', 'teacher', 'manager') AND c.id != ?";
                                $courses = $DB->get_records_sql($sql, [$USER->id, SITEID]);
                            }
                            ?>
                            <select name="enroll_course_id" class="form-select form-select-sm" style="width: auto;">
                                <option value="">-- Select Course to Enroll --</option>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?php echo $c->id; ?>"><?php echo s($c->fullname); ?></option>
                                <?php endforeach; ?>
                            </select>
                            
                            <button type="submit" name="ml_action" value="create_accounts" class="btn btn-sm btn-success mx-2">➕ Create Moodle Accounts & Enroll</button>
                            <button type="submit" name="ml_action" value="remove" class="btn btn-sm btn-danger">🗑️ Remove from Masterlist</button>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm mt-4">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0">📝 ARAL Post-Test Configurations</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">Module Under Construction. Post-test assessments will be configured here.</p>
                </div>
            </div>

        </div>
    </div>
</div>


    <div class="row d-none" id="dashboard-details-view">
        <div class="col-12">
            <div class="card shadow-sm border-0 mb-5">
                <div class="card-header bg-white border-bottom p-3 d-flex align-items-center">
                    <button class="btn btn-outline-secondary mr-3" onclick="closeDetailsView()">⬅️ Back to Submissions</button>
                    <h4 class="mb-0 text-primary" id="details-student-name">Student Name</h4>
                </div>
                <div class="card-body p-4" id="details-content-area" style="background: #f8f9fa;">
                    <!-- JS will render here -->
                </div>
            </div>
        </div>
    </div>

<!-- Modal -->

</div>

<script>
let stateData = {};

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe.toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

function viewStudentAnswers(btn, profId) {
    const attemptRaw = btn.getAttribute('data-attempts');
    if (!attemptRaw) return;
    const attempts = JSON.parse(attemptRaw);
    if (attempts.length === 0) return;
    
    stateData['current_prof'] = profId;
    stateData[profId] = attempts;
    
    document.getElementById('details-student-name').innerText = attempts[0].student_name;
    
    document.getElementById('dashboard-main-view').classList.add('d-none');
    document.getElementById('dashboard-details-view').classList.remove('d-none');
    window.scrollTo(0, 0);
    
    renderDetailsBody(0, profId);
}

function closeDetailsView() {
    document.getElementById('dashboard-details-view').classList.add('d-none');
    document.getElementById('dashboard-main-view').classList.remove('d-none');
    window.scrollTo(0, 0);
}

function renderDetailsBody(idx, profId) {
    const container = document.getElementById('details-content-area');
    const attempts = stateData[profId];
    const att = attempts[idx];
    
    let html = '<div class="d-flex w-100 border-bottom mb-4 pb-2 justify-content-center">';
    
    // Navbar for tests
    attempts.forEach((a, i) => {
        const isActive = (i === idx);
        let color = isActive ? '#fff' : '#495057';
        let bg = isActive ? '#0d6efd' : '#e9ecef';
        let shadow = isActive ? 'shadow-sm' : '';
        html += `<div onclick="renderDetailsBody(${i}, ${profId})" class="${shadow}" style="padding: 10px 30px; cursor: pointer; font-weight: bold; border-radius: 5px; color: ${color}; background: ${bg}; margin: 0 10px; transition: all 0.2s;">
                    ${escapeHtml(a.test_name)}
                 </div>`;
    });
    html += '</div>';
    
    if (att.taken === false) {
        html += `<div class="row justify-content-center mt-5"><div class="col-md-8 text-center text-muted">
                    <h4>No Data Available</h4>
                    <p>The student did not take the <strong>${escapeHtml(att.test_name)}</strong> assessment.</p>
                 </div></div>`;
        container.innerHTML = html;
        return;
    }
    
    // Check if it's Non-Reader (Level 3)
    if (att.level_idx === 3) {
        html += `<div class="row justify-content-center"><div class="col-md-10">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white"><h5 class="font-weight-bold mb-0 text-primary">📝 Comprehension Test Result</h5></div>
                        <div class="card-body">
                            ${renderComprehension(att)}
                        </div>
                    </div>
                 </div></div>`;
        container.innerHTML = html;
        return;
    }
    
    // STANDARD VIEW
    
    // Metrics Banner
    let pJson = null;
    try { pJson = JSON.parse(att.philiri_json || '{}'); } catch(e) {}
    
    const wordsCount = (pJson && pJson.referenceWords) ? pJson.referenceWords.length : 0;
    
    let miscuesCount = 0;
    if (pJson && pJson.counts) {
        const c = pJson.counts;
        miscuesCount = (c.mispronunciation||0) + (c.omission||0) + (c.insertion||0) + (c.substitution||0) + (c.reversal||0);
    }
    
    let wordsCorrectlyRead = wordsCount > 0 ? (wordsCount - miscuesCount) : 0;
    if (wordsCorrectlyRead < 0) wordsCorrectlyRead = 0;
    let percentageCorrect = wordsCount > 0 ? ((wordsCorrectlyRead / wordsCount) * 100).toFixed(1) : 0;
    
    html += `<div class="row justify-content-center mb-4">
                <div class="col-md-10">
                    <div class="d-flex justify-content-around bg-white p-4 border rounded shadow-sm text-center">
                        <div><div class="text-muted small text-uppercase font-weight-bold tracking-wide">Reading Speed</div><div class="font-weight-bold" style="font-size: 2rem; color: #0d6efd;">${att.wpm || 0} <span style="font-size: 1rem;">WPM</span></div></div>
                        <div><div class="text-muted small text-uppercase font-weight-bold tracking-wide">Words Correct</div><div class="font-weight-bold" style="font-size: 2rem; color: #198754;">${wordsCorrectlyRead} <span style="font-size: 1rem;">/ ${wordsCount}</span></div></div>
                        <div><div class="text-muted small text-uppercase font-weight-bold tracking-wide">Word Accuracy</div><div class="font-weight-bold" style="font-size: 2rem; color: #6610f2;">${percentageCorrect}%</div></div>
                        <div><div class="text-muted small text-uppercase font-weight-bold tracking-wide">Comprehension</div><div class="font-weight-bold" style="font-size: 2rem; color: #fd7e14;">${att.comp || 0}%</div></div>
                    </div>
                </div>
             </div>`;
             
    // Audio Player
    html += `<div class="row justify-content-center mb-4">
                <div class="col-md-10">
                    <audio controls src="serve_audio.php?id=${att.id}" class="w-100 shadow-sm" style="border-radius: 50px;"></audio>
                </div>
             </div>`;
             
        // DROPDOWN SELECTOR
    const accId = 'acc-' + att.id;
    html += `<div class="row justify-content-center mb-3">
                <div class="col-md-10">
                    <select class="form-control form-control-lg shadow-sm font-weight-bold" style="border-radius: 10px; cursor: pointer; border: 2px solid #0d6efd; color: #0d6efd;" onchange="switchSection(this.value, '${att.id}')">
                        <option value="philiri">🗣️ Phil-IRI Style</option>
                        <option value="comprehension">📝 Comprehension Test</option>
                    </select>
                </div>
             </div>`;
             
    html += `<div class="row justify-content-center"><div class="col-md-10">`;
    
    // 3. Phil-IRI Style
    html += `
        <div id="sec-philiri-${att.id}" class="card border-0 shadow-sm rounded">
            <div class="card-body bg-white rounded">
                                <div class="border-bottom pb-2 mb-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 text-primary">🗣️ Phil-IRI Style</h5>
                    <button class="btn btn-sm btn-outline-secondary" onclick="regenerateMarkup('${att.id}')" title="Regenerate missing markup from data">⚙️ Re-render</button>
                </div>
                ${renderPhilIri(att)}
            </div>
        </div>
    `;
    
    // 4. Comprehension
    html += `
        <div id="sec-comprehension-${att.id}" class="card border-0 shadow-sm rounded d-none">
            <div class="card-body bg-white rounded p-4">
                <h5 class="border-bottom pb-3 mb-4 text-primary">📝 Comprehension Test</h5>
                ${renderComprehension(att)}
            </div>
        </div>
    `;
    
    html += `</div></div>`; // End sections
    container.innerHTML = html;
}

async function regenerateMarkup(attId) {
    const attempts = stateData[stateData['current_prof']];
    const att = attempts.find(a => a.id == attId);
    if (!att) return;
    
    let pJson = null;
    try { pJson = JSON.parse(att.philiri_json || '{}'); } catch(e) {}
    if (!pJson || !pJson.ops) {
        alert("Cannot rebuild markup: Missing operations data in database.");
        return;
    }
    
    let eData = null;
    try { eData = JSON.parse(att.evaluation_data || '{}'); } catch(e) {}
    
    let newMarkup = "";
    pJson.ops.forEach(op => {
        let azJson = "";
        if (eData && eData.azure && eData.azure.words && op.spoken !== undefined) {
            const azw = eData.azure.words[op.spoken];
            if (azw) azJson = `data-azure='${escapeHtml(JSON.stringify(azw))}'`;
        }
        if (op.type === 'match') {
            const word = pJson.referenceWords[op.ref].word;
            newMarkup += `<span class="transcript-token" ${azJson}>${escapeHtml(word)}</span> `;
        } else if (op.type === 'mis') {
            const word = pJson.referenceWords[op.ref].word;
            newMarkup += `<span class="transcript-token" ${azJson}><span class="mark mis" data-spoken="${escapeHtml(op.spokenWord || '')}">${escapeHtml(word)}</span></span> `;
        } else if (op.type === 'om') {
            const word = pJson.referenceWords[op.ref].word;
            newMarkup += `<span class="transcript-token" ${azJson}><span class="mark om">${escapeHtml(word)}</span></span> `;
        } else if (op.type === 'ins') {
            const word = op.spokenWord;
            newMarkup += `<span class="transcript-token" ${azJson}><span class="mark ins">${escapeHtml(word)}</span></span> `;
        } else if (op.type === 'sub') {
            const word = pJson.referenceWords[op.ref].word;
            newMarkup += `<span class="transcript-token" ${azJson}><span class="mark sub" data-spoken="${escapeHtml(op.spokenWord || '')}">${escapeHtml(word)}</span></span> `;
        } else if (op.type === 'rev') {
            const word = pJson.referenceWords[op.ref].word;
            newMarkup += `<span class="transcript-token" ${azJson}><span class="mark rev">${escapeHtml(word)}</span></span> `;
        } else if (op.type === 'trans') {
            const word = pJson.referenceWords[op.ref].word;
            newMarkup += `<span class="transcript-token" ${azJson}><span class="mark trans">${escapeHtml(word)}</span></span> `;
        } else if (op.type === 'rep') {
            const word = op.spokenWord;
            newMarkup += `<span class="transcript-token" ${azJson}><span class="mark rep">${escapeHtml(word)}</span></span> `;
        }
    });
    
    const container = document.getElementById('markup-container-' + attId);
    if (container) {
        container.innerHTML = newMarkup;
        await saveMarkupOverrides(attId);
    }
}

function switchSection(sectionVal, attId) {
    document.getElementById('sec-philiri-' + attId).classList.add('d-none');
    document.getElementById('sec-comprehension-' + attId).classList.add('d-none');
    document.getElementById('sec-' + sectionVal + '-' + attId).classList.remove('d-none');
}

function cycleErrorType(el) {
    const classList = el.classList;
    if (classList.contains('mis')) {
        classList.remove('mis'); classList.add('om'); el.title = "Omission";
    } else if (classList.contains('om')) {
        classList.remove('om'); classList.add('sub'); el.title = "Substitution";
    } else if (classList.contains('sub')) {
        classList.remove('sub'); classList.add('ins'); el.title = "Insertion";
    } else if (classList.contains('ins')) {
        classList.remove('ins'); classList.add('rep'); el.title = "Repetition";
    } else if (classList.contains('rep')) {
        classList.remove('rep'); classList.add('trans'); el.title = "Transposition";
    } else if (classList.contains('trans')) {
        classList.remove('trans'); classList.add('rev'); el.title = "Reversal";
    } else if (classList.contains('rev')) {
        classList.remove('rev', 'mark'); el.title = "Correct";
    } else {
        classList.add('mark', 'mis'); el.title = "Mispronunciation";
    }
}

async function saveMarkupOverrides(attId) {
    const container = document.getElementById('markup-container-' + attId);
    if (!container) return;
    const btn = document.getElementById('save-markup-' + attId);
    btn.disabled = true;
    btn.textContent = "Saving...";
    
    const newMarkup = container.innerHTML;
    let counts = { mispronunciation: 0, omission: 0, insertion: 0, substitution: 0, repetition: 0, transposition: 0, reversal: 0, self_corrected: 0 };
    
    container.querySelectorAll('.mark').forEach(el => {
        if (el.classList.contains('mis')) counts.mispronunciation++;
        if (el.classList.contains('om')) counts.omission++;
        if (el.classList.contains('ins')) counts.insertion++;
        if (el.classList.contains('sub')) counts.substitution++;
        if (el.classList.contains('rep')) counts.repetition++;
        if (el.classList.contains('trans')) counts.transposition++;
        if (el.classList.contains('rev')) counts.reversal++;
        if (el.classList.contains('self-corrected')) counts.self_corrected++;
    });
    
    const tokenEls = container.querySelectorAll('.transcript-token');
    const wordCount = tokenEls.length || 1; // prevent div 0
    let totalMiscues = counts.mispronunciation + counts.omission + counts.insertion + counts.substitution + counts.reversal;
    let newAccuracy = ((wordCount - totalMiscues) / wordCount) * 100;
    if (newAccuracy < 0) newAccuracy = 0;
    
    const formData = new FormData();
    formData.append('action', 'save_markup');
    formData.append('att_id', attId);
    formData.append('markup', newMarkup);
    formData.append('counts', JSON.stringify(counts));
    formData.append('accuracy', newAccuracy);
    
    try {
        const res = await fetch('external_manage.php', { method: 'POST', body: formData });
        alert(`Saved! New Accuracy: ${newAccuracy.toFixed(1)}%`);
        location.reload();
    } catch (err) {
        alert("Failed to save.");
    }
}

function renderPhilIri(att) {
    let pJson = null;
    try { pJson = JSON.parse(att.philiri_json || '{}'); } catch(e) {}
    
    let html = `<div style="font-size: 1.15rem; line-height: 2.2; padding: 15px; border: 1px dashed #ccc; border-radius: 8px;">
        <div class="mb-3 d-flex justify-content-between align-items-center">
            <span class="badge badge-info p-2">Interactive Transcript (Click words to toggle miscue types)</span>
            <button id="save-markup-${att.id}" class="btn btn-sm btn-outline-success font-weight-bold shadow-sm" onclick="saveMarkupOverrides(${att.id})">💾 Save Overrides & Recalculate</button>
        </div>
        <div id="markup-container-${att.id}" class="bg-light p-3 border rounded" style="cursor: pointer; min-height: 150px;" onclick="if(event.target.closest('.transcript-token') || event.target.closest('.mark')) { let target = event.target.closest('.mark') || event.target.closest('.transcript-token').querySelector('.mark') || event.target.closest('.transcript-token'); if(target && target.tagName !== 'DIV') cycleErrorType(target); }">`;
        
    if (pJson && pJson.markup) {
        html += pJson.markup;
    } else {
        html += `<span class="text-muted small">No markup available</span>`;
    }
    
    html += `</div></div>`;
    return html;
}

function renderComprehension(att) {
    const answers = JSON.parse(att.answers_json || '{}');
    const questions = JSON.parse(att.questions_json || '[]');
    const isPending = !att.classification || att.classification.includes("Pending");
    
    if (Object.keys(answers).length === 0) return '<div class="text-muted small">No answers.</div>';
    
    let html = '<div style="font-size: 1rem;">';
    html += `<form id="manualGradeForm" method="POST" action="external_manage.php">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="manual_grade">
                <input type="hidden" name="att_id" value="${att.id}">`;
                
    for (const [qIdx, ans] of Object.entries(answers)) {
        let displayAns = ans;
        let questionText = `Q${parseInt(qIdx) + 1}`;
        let statusHtml = '';
        
        let q = null;
        if (questions.length > 0) {
            let actualQIdx = 0;
            for (let k=0; k<questions.length; k++) {
                if (parseInt(questions[k].level_idx || 0) === att.level_idx) {
                    if (actualQIdx === parseInt(qIdx)) { q = questions[k]; break; }
                    actualQIdx++;
                }
            }
        }
        
        if (q && q.text) questionText = q.text;
        
        if (q) {
            if (q.type === 'description') {
                continue;
            }
            if (q.type === 'multichoice') {
                let optIdx = parseInt(ans);
                if (!isNaN(optIdx) && q.options && q.options[optIdx] !== undefined) {
                    displayAns = escapeHtml(q.options[optIdx]);
                }
                
                if (!isNaN(optIdx) && optIdx === parseInt(q.correct)) {
                    statusHtml = '<span class="badge badge-success p-2">Correct</span>';
                } else if (!isNaN(optIdx)) {
                    statusHtml = '<span class="badge badge-danger p-2">Incorrect</span>';
                }
            } else if (q.type === 'truefalse') {
                let boolAns = (ans === 'true' || ans === true || String(ans).toLowerCase() === 'true');
                displayAns = boolAns ? "True" : "False";
                let boolCorrect = (q.correct === 'true' || q.correct === true || String(q.correct).toLowerCase() === 'true');
                
                if (boolAns === boolCorrect) {
                    statusHtml = '<span class="badge badge-success p-2">Correct</span>';
                } else {
                    statusHtml = '<span class="badge badge-danger p-2">Incorrect</span>';
                }
            } else if (q.type === 'matching') {
                if (q.pairs) {
                    let allCorrect = true;
                    displayAns = "<ul style='margin:10px 0; padding-left:1.5rem;'>";
                    for (const [pIdx, matchVal] of Object.entries(ans)) {
                        displayAns += `<li>${escapeHtml(q.pairs[pIdx].question)} <span class="text-muted">➔</span> <strong>${escapeHtml(matchVal)}</strong></li>`;
                        if (matchVal !== q.pairs[pIdx].answer) allCorrect = false;
                    }
                    displayAns += "</ul>";
                    statusHtml = allCorrect ? '<span class="badge badge-success p-2">Correct</span>' : '<span class="badge badge-warning p-2">Partial/Incorrect</span>';
                }
            } else if (q.type === 'shortanswer' || q.type === 'essay') {
                if (isPending) {
                    statusHtml = `<select name="manual_grade_${qIdx}" class="form-control mt-2" style="max-width: 200px;">
                                    <option value="1">Correct (1 pt)</option>
                                    <option value="0" selected>Incorrect (0 pt)</option>
                                  </select>`;
                } else {
                    statusHtml = '<span class="badge badge-info p-2">Manually Graded</span>';
                }
            }
        }
        
        if (!statusHtml) statusHtml = '<span class="badge badge-secondary p-2">Pending</span>';
        
        html += `<div class="mb-4 pb-3 border-bottom">
                    <strong style="font-size: 1.1rem; color: #343a40;">${escapeHtml(questionText)}</strong><br>
                    <div class="mt-2 text-dark" style="font-size: 1.05rem;">${q && q.type === 'matching' ? displayAns : escapeHtml(displayAns)}</div>
                    <div class="mt-2">${statusHtml}</div>
                 </div>`;
    }
    
    if (isPending) {
        html += `<button type="submit" class="btn btn-primary btn-lg mt-3 w-100 font-weight-bold shadow-sm">Save Final Grade</button>`;
    }
    
    html += '</form></div>';
    return html;
}
// Tooltip logic for Teacher Dashboard
const ttip = document.createElement('div');
ttip.style.cssText = 'position: fixed; background: rgba(0,0,0,0.85); color: #fff; padding: 8px 12px; border-radius: 6px; font-size: 0.95rem; z-index: 10000; pointer-events: none; display: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); font-family: sans-serif;';
document.body.appendChild(ttip);

document.addEventListener('mouseover', function(e) {
    const token = e.target.closest('.transcript-token');
    if (!token) return;
    const mark = token.querySelector('.mark') || (token.classList.contains('mark') ? token : null);
    if (!mark) return;
    
    let errType = "";
    if (mark.classList.contains('mis')) errType = "Mispronunciation";
    else if (mark.classList.contains('om')) errType = "Omission";
    else if (mark.classList.contains('ins')) errType = "Insertion";
    else if (mark.classList.contains('sub')) errType = "Substitution";
    else if (mark.classList.contains('rep')) errType = "Repetition";
    else if (mark.classList.contains('trans')) errType = "Transposition";
    else if (mark.classList.contains('rev')) errType = "Reversal";
    else if (mark.classList.contains('self-corrected')) errType = "Self-Corrected";
    
    let html = "";
    if (errType) {
        html += `<strong>${errType}</strong>`;
        if (mark.dataset.spoken) {
            html += `<br><span style="color: #ffc107;">Said: ${escapeHtml(mark.dataset.spoken)}</span>`;
        }
    }
    
    if (token.dataset.azure) {
        try {
            const az = JSON.parse(token.dataset.azure);
            html += `<hr style="margin: 5px 0; border-color: #555;">
                     <div style="font-size: 0.8rem; color: #aaa;">AZURE PRONUNCIATION</div>
                     <div>Expected: <strong>${escapeHtml(az.syllables && az.syllables[0] ? az.syllables[0].Grapheme : token.textContent.trim())}</strong></div>
                     <div>Detected: <strong style="color: #0dcaf0;">${escapeHtml(az.word || '')}</strong></div>
                     <div style="font-size: 0.8rem; color: #aaa; margin-top: 4px;">Time: <strong>${az.offsetSeconds !== undefined ? az.offsetSeconds.toFixed(2) + 's - ' + az.endSeconds.toFixed(2) + 's' : 'N/A'}</strong></div>
                     <div style="font-size: 0.8rem; margin-top: 4px; color: #ccc;">Expected Phonemes: <span style="color: #fff; font-family: monospace;">${escapeHtml(az.syllables && az.syllables[0] ? az.syllables[0].Syllable : '')}</span></div>
                     <div style="font-size: 0.8rem; margin-top: 2px;">Detected: `;
            if (az.phonemes) {
                az.phonemes.forEach(ph => {
                    html += `<span style="background: #222; padding: 2px 4px; border-radius: 3px; margin-right: 2px; font-family: monospace;">${escapeHtml(ph.phoneme)} <span style="color:${ph.accuracy > 80 ? '#198754' : '#dc3545'};">${ph.accuracy}</span></span>`;
                });
            }
            html += `</div>`;
        } catch(e) {}
    }
    
    if (html) {
        ttip.innerHTML = html;
        ttip.style.display = 'block';
    }
});

document.addEventListener('mousemove', function(e) {
    if (ttip.style.display === 'block') {
        ttip.style.left = (e.clientX + 15) + 'px';
        ttip.style.top = (e.clientY > window.innerHeight - 50 ? e.clientY - 40 : e.clientY + 15) + 'px';
    }
});

document.addEventListener('mouseout', function(e) {
    const token = e.target.closest('.transcript-token');
    if (token) ttip.style.display = 'none';
});
</script>

<?php
$env_content = file_exists(__DIR__ . '/v536/.env') ? file_get_contents(__DIR__ . '/v536/.env') : '';
$env_vars = [];
foreach (explode("\n", $env_content) as $line) {
    if (strpos(trim($line), '#') === 0 || empty(trim($line))) continue;
    $parts = explode('=', $line, 2);
    if (count($parts) == 2) {
        $env_vars[trim($parts[0])] = trim($parts[1]);
    }
}
?>

<!-- ENV Modal -->
<div class="modal fade" id="envModal" tabindex="-1" role="dialog" aria-hidden="true" style="z-index: 1050; background: rgba(0,0,0,0.5);">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title">⚙️ API Configuration</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" action="external_manage.php">
          <div class="modal-body">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="save_env">
            
            <h6 class="border-bottom pb-2">OpenAI Settings</h6>
            <div class="form-group row mb-2">
                <label class="col-sm-4 col-form-label">OpenAI API Key</label>
                <div class="col-sm-8">
                    <input type="password" name="AZURE_OPENAI_API_KEY" class="form-control" value="<?php echo s($env_vars['AZURE_OPENAI_API_KEY'] ?? ''); ?>" placeholder="sk-proj-...">
                </div>
            </div>
            <div class="form-group row mb-2">
                <label class="col-sm-4 col-form-label">Transcription Model</label>
                <div class="col-sm-8">
                    <input type="text" name="REALTIME_TRANSCRIPTION_MODEL" class="form-control" value="<?php echo s($env_vars['REALTIME_TRANSCRIPTION_MODEL'] ?? 'gpt-live-transcribe'); ?>">
                </div>
            </div>
            
            <h6 class="border-bottom pb-2 mt-4">Azure Settings</h6>
            <div class="form-group row mb-2">
                <label class="col-sm-4 col-form-label">Azure Speech Key</label>
                <div class="col-sm-8">
                    <input type="password" name="SPEECH_KEY" class="form-control" value="<?php echo s($env_vars['SPEECH_KEY'] ?? ''); ?>">
                </div>
            </div>
            <div class="form-group row mb-2">
                <label class="col-sm-4 col-form-label">Speech Region</label>
                <div class="col-sm-8">
                    <input type="text" name="SPEECH_REGION" class="form-control" value="<?php echo s($env_vars['SPEECH_REGION'] ?? 'southeastasia'); ?>">
                </div>
            </div>
            
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">💾 Save Configuration</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script>
// Manually handle modal toggle natively to bypass Moodle AMD block
document.addEventListener('DOMContentLoaded', function() {
    const modalBtn = document.querySelector('[data-bs-target="#envModal"]');
    const modalEl = document.getElementById('envModal');
    const closeBtns = document.querySelectorAll('[data-bs-dismiss="modal"]');
    
    if (modalBtn && modalEl) {
        modalBtn.addEventListener('click', function(e) {
            e.preventDefault();
            modalEl.classList.add('show');
            modalEl.style.display = 'block';
            document.body.classList.add('modal-open');
        });
        
        closeBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                modalEl.classList.remove('show');
                modalEl.style.display = 'none';
                document.body.classList.remove('modal-open');
            });
        });
    }
});
</script>
<?php echo $OUTPUT->footer(); ?>
