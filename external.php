<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$token = optional_param('token', '', PARAM_ALPHANUMEXT);
$action = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_url('/mod/readingassessment/external.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('ARAL Reading Program - Phil-IRI Assessment');
$PAGE->set_heading('ARAL Reading Program - Phil-IRI Assessment');

// Handle Intake Form Submission
if ($action === 'intake' && data_submitted()) {
    $firstname = optional_param('firstname', '', PARAM_TEXT);
    $middleinitial = optional_param('middleinitial', '', PARAM_TEXT);
    $lastname = optional_param('lastname', '', PARAM_TEXT);
    $gender = optional_param('gender', '', PARAM_TEXT);
    $lrn = optional_param('lrn', '', PARAM_TEXT);
    $email = optional_param('email', '', PARAM_TEXT);
    $section = optional_param('section', '', PARAM_TEXT);
    $age = optional_param('age', 0, PARAM_INT);
    $grade = optional_param('grade_level', 7, PARAM_INT);
    $consent = optional_param('consent', 0, PARAM_INT);

    if (empty($firstname) || empty($lastname) || empty($lrn) || empty($gender) || empty($age) || !$consent || empty($grade)) {
        redirect(new moodle_url('/mod/readingassessment/external.php'), 'Please fill all required fields and agree to the consent.', null, \core\output\notification::NOTIFY_ERROR);
    }

    $new_token = bin2hex(random_bytes(16));

    $profile = new stdClass();
    $profile->token = $new_token;
    $profile->firstname = $firstname;
    $profile->middleinitial = $middleinitial;
    $profile->lastname = $lastname;
    $profile->lrn = $lrn;
    $profile->gender = $gender;
    $profile->email = $email;
    $profile->section = $section;
    $profile->age = $age;
    $profile->grade_level = $grade;
    $profile->consent_agreed = $consent;
    $profile->timecreated = time();

    $DB->insert_record('readingassessment_ext_prof', $profile);

    redirect(new moodle_url('/mod/readingassessment/external.php', ['token' => $new_token]));
}

// Handle Resume Form Submission
if ($action === 'resume' && data_submitted()) {
    $resume_name = trim(optional_param('resume_name', '', PARAM_TEXT));
    $resume_lrn = trim(optional_param('resume_lrn', '', PARAM_TEXT));
    
    $profile = $DB->get_record('readingassessment_ext_prof', ['lrn' => $resume_lrn, 'lastname' => $resume_name], '*', IGNORE_MULTIPLE);
    if ($profile) {
        redirect(new moodle_url('/mod/readingassessment/external.php', ['token' => $profile->token]));
    } else {
        redirect(new moodle_url('/mod/readingassessment/external.php'), 'No profile found with that Last Name and LRN.', null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Ensure passages are fetched for the testing flow
$passage_data = null;
if (!empty($token)) {
    $profile = $DB->get_record('readingassessment_ext_prof', ['token' => $token]);
    if ($profile) {
        $passage_data = $DB->get_record('readingassessment_ext_pass', ['grade_level' => $profile->grade_level]);
    }
}


echo $OUTPUT->header();

if (empty($token) || empty($profile)) {
    // STATE 1: No Token -> Show Intake & Consent Form
?>
    <div class="container mt-5" style="max-width: 700px;">
        <div class="card shadow-lg border-0 rounded-lg">
            <div class="card-header bg-primary text-white text-center py-4">
                <h3 class="mb-0">📖 ARAL Program Screening</h3>
                <p class="mb-0 text-white-50">Phil-IRI Reading Assessment</p>
            </div>
            <div class="card-body p-5">
                <div class="alert alert-info border-info mb-4" style="background-color: #f8fbff;">
                    <h5 class="alert-heading font-weight-bold">🎙️ Privacy Notice & Audio Consent</h5>
                    <p class="mb-0 text-dark">This assessment uses your microphone to analyze your reading fluency in real-time. Your voice is securely processed by the AI. Only your reading speed, accuracy score, and comprehension answers are stored to help teachers evaluate your progress.</p>
                </div>

                <form action="external.php" method="POST">
                    <input type="hidden" name="action" value="intake">
                    
                    <div class="row">
                        <div class="col-md-5 form-group mb-4">
                            <label class="font-weight-bold text-secondary">First Name</label>
                            <input type="text" name="firstname" class="form-control form-control-lg" required>
                        </div>
                        <div class="col-md-2 form-group mb-4">
                            <label class="font-weight-bold text-secondary">M.I.</label>
                            <input type="text" name="middleinitial" class="form-control form-control-lg">
                        </div>
                        <div class="col-md-5 form-group mb-4">
                            <label class="font-weight-bold text-secondary">Last Name</label>
                            <input type="text" name="lastname" class="form-control form-control-lg" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-5 form-group mb-4">
                            <label class="font-weight-bold text-secondary">Learner Reference Number (LRN)</label>
                            <input type="text" name="lrn" class="form-control form-control-lg" placeholder="12-digit LRN" required pattern="\d{12}">
                        </div>
                        <div class="col-md-4 form-group mb-4">
                            <label class="font-weight-bold text-secondary">Gender</label>
                            <select name="gender" class="form-control form-control-lg" required>
                                <option value="" disabled selected>Select...</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="col-md-3 form-group mb-4">
                            <label class="font-weight-bold text-secondary">Age</label>
                            <input type="number" name="age" class="form-control form-control-lg" min="4" max="100" required>
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-secondary">Email</label>
                        <input type="email" name="email" class="form-control form-control-lg">
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group mb-4">
                            <label class="font-weight-bold text-secondary">Section</label>
                            <input type="text" name="section" class="form-control form-control-lg" required>
                        </div>
                        <div class="col-md-6 form-group mb-4">
                            <label class="font-weight-bold text-secondary">Grade Level</label>
                            <select name="grade_level" class="form-control form-control-lg" required>
                                <option value="7">Grade 7</option>
                                <option value="8">Grade 8</option>
                                <option value="9">Grade 9</option>
                                <option value="10">Grade 10</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group form-check mb-5 custom-control custom-checkbox text-center">
                        <input type="checkbox" name="consent" value="1" class="custom-control-input" id="consentCheck" required>
                        <label class="custom-control-label text-secondary" for="consentCheck" style="font-size: 1.1rem; padding-top:2px;">I agree to use my microphone for this reading test.</label>
                    </div>

                    <button type="submit" class="btn btn-success btn-lg btn-block font-weight-bold shadow-sm" style="font-size: 1.25rem;">Start Assessment</button>
                </form>
            </div>
            
            <div class="card-footer bg-light text-center py-4 border-top-0">
                <h5 class="text-secondary mb-3">Already taken the assessment?</h5>
                <form action="external.php" method="POST" class="form-inline justify-content-center">
                    <input type="hidden" name="action" value="resume">
                    <input type="text" name="resume_name" class="form-control mr-2 mb-2" placeholder="Last Name" required>
                    <input type="text" name="resume_lrn" class="form-control mr-2 mb-2" placeholder="LRN" required>
                    <button type="submit" class="btn btn-outline-primary mb-2">Resume Session</button>
                </form>
            </div>
        </div>
    </div>
<?php
} else {
    // STATE 2: Assessment Flow (v536 App)
    $passages_json = json_encode($passage_data);
?>
    <!-- Injecting v536 interface and logic -->
    <link rel="stylesheet" href="v536/public/style.css">
    
    <div id="app-container" style="max-width: 1200px; margin: 0 auto;">
        <!-- v536 HTML structure will go here, heavily modified to pull from $passages -->
        <h3 class="text-center mt-4">Phil-IRI Assesment</h3>
        <main class="shell">
    <header class="topbar">
      <div>
        <div class="eyebrow">Phil-IRI Assesment</div>
        <h1>Read aloud</h1>
      </div>
      <div class="top-actions">
        <label class="locale-label" hidden>Language
          <select id="locale">
            <option value="en-US" selected>English (Philippines)</option>
            <option value="en-US">English (US)</option>
            <option value="en-GB">English (UK)</option>
            <option value="fil-PH">Filipino (Philippines)</option>
          </select>
        </label>
        <button id="diagBtn" class="ghost icon-btn" title="Diagnostics">⚙️</button>
        <span class="status-pill" id="statusPill">Ready</span>
      </div>
    </header>

    <section class="top-metrics" aria-label="Reading setup">
      <div class="metric"><span>TIME</span><strong id="timer">00:00</strong></div>
      <div class="metric"><span>STORY WORDS</span><strong id="storyWordCount">0</strong></div>
            <button class="metric metric-button" id="editStoryBtn" style="display:none !important;"><span>PASSAGE</span><strong>Edit</strong></button>
    </section>
      <!-- COMPREHENSION TEST -->
      <div id="comprehension-section" class="card shadow-sm mt-4 d-none" style="border: 2px solid #0d6efd; border-radius: 8px;">
        <div class="card-header bg-primary text-white">
          <h4 class="mb-0">📝 Comprehension Test</h4>
        </div>
        <div class="card-body" id="comprehension-questions">
          <!-- JS will inject questions here -->
        </div>
        <div class="card-footer text-right">
            <button id="submitTestBtn" class="btn btn-success btn-lg font-weight-bold shadow">Submit & Continue</button>
        </div>
      </div>


    <section class="reader-card">
      <div class="reader-toolbar">
        <div class="toolbar-status">
          <span class="dot" id="vadDot"></span>
          <span id="hint">Ready. Press Start and read the passage.</span>
        </div>
        <div class="mic-meter" aria-label="Microphone activity"><div id="meterBar"></div></div>
      </div>

      <textarea id="storyEditor" class="story-editor" hidden></textarea>

      <div class="reader-grid">
        <section class="reading-pane" aria-label="Expected story">
          <div class="pane-heading">
            <div><span class="pane-kicker">EXPECTED STORY</span><span class="pane-sub">Reference passage · kept clean</span></div>
            <span class="word-count-badge" id="wordBadge">0 words</span>
          </div>
          <article id="story" class="story"></article>
        </section>

        <section class="reading-pane transcript-pane" aria-label="Spoken transcript and Azure assessment">
          <div class="pane-heading">
            <div><span class="pane-kicker">TRANSCRIPT / AZURE</span><span class="pane-sub">OpenAI live transcript + Azure pronunciation evidence</span></div>
            <span class="live-badge" id="liveBadge">Realtime</span>
          </div>
          <div id="transcript" class="transcript" aria-live="polite">
            <span class="empty-state">Your spoken words will appear here while you read.</span>
          </div>
          <div id="transcriptNotice" class="transcript-notice" hidden></div>
        </section>
      </div>

      <div class="action-buttons">
        <button id="startBtn" class="primary">▶ Start</button>
        <button id="stopBtn" class="secondary" disabled>⏹ Stop</button>
        <button id="restartBtn" class="ghost" hidden>🔄 Restart</button>
        <button id="resetBtn" class="ghost">Reset</button>
      </div>

      <div class="legend" aria-label="Miscue legend">
        <span class="legend-item"><i class="legend-mis"></i> Mispronunciation</span>
        <span class="legend-item"><i class="legend-om"></i> Omission</span>
        <span class="legend-item"><i class="legend-sub"></i> Substitution</span>
        <span class="legend-item"><i class="legend-rep"></i> Repetition</span>
        <span class="legend-item"><i class="legend-trans"></i> Transposition</span>
        <span class="legend-item"><i class="legend-rev"></i> Reversal</span>
        <span class="legend-item"><i class="legend-self"></i> Self-correction</span>
      </div>

      <div class="bottom-row">
        <div class="performance-metrics">
          <div class="performance-card speed-card"><span>READING SPEED</span><strong id="wpm">—</strong><small>(Words Read &divide; Time in Seconds) &times; 60</small></div>
          <div class="performance-card accuracy-card"><span>READING ACCURACY</span><strong id="readingAccuracy">—</strong><small>(Words − Miscues) &divide; Words &times; 100</small></div>
        </div>
        <div class="miscue-strip" id="miscueStrip" aria-label="Miscue counts"></div>
      </div>

      <dialog id="diagModal" class="diag-modal">
        <div class="diag-modal-header">
          <h3>Assessment Diagnostics</h3>
          <button id="closeDiagBtn" class="ghost">Close</button>
        </div>
        <div id="diagContent" class="diag-modal-content">No assessment yet.</div>
      </dialog>
    </section>

    <div id="tooltip" class="word-tooltip" role="tooltip" hidden></div>
    <footer>Reference = story. Spoken sequence = OpenAI Realtime with soft passage context. Context never replaces the spoken sequence. All scoring markup = transcript side. Pronunciation evidence = Azure. Realtime transcript failure never becomes automatic omissions.</footer>
  </main>
    </div>
    
    <script>
        const API_TOKEN = "<?php echo $token; ?>";
        const PASSAGES = <?php echo $passages_json; ?>;
        // The cascading logic and v536 integration will run here.
    </script>
    <script src="v536/public/assessment-core.js"></script>
    <script src="v536/public/app.js?v=4.5.38"></script>
    <script src="v536/public/cascading-flow.js?v=4.5.42"></script>

<?php
}
echo $OUTPUT->footer();
