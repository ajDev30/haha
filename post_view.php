<?php
/**
 * post_view.php — Student-facing post-test reading assessment.
 *
 * Loads the reading passage from the activity configuration,
 * embeds the existing v536 engine (app.js + assessment-core.js),
 * renders the comprehension questionnaire,
 * and wires up the AMD modules (post_recorder.js, post_coach.js).
 *
 * IMPORTANT: app.js reads DOM elements at script-execution time
 * (window.originalStory = els.story.textContent), so it MUST be
 * loaded AFTER the DOM elements are rendered — at the bottom of body.
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$cmid = required_param('cmid', PARAM_INT);

$cm      = get_coursemodule_from_id('readingassessment', $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cmid);

require_login($course, true, $cm);
require_capability('mod/readingassessment:view', $context);

$PAGE->set_url(new moodle_url('/mod/readingassessment/post_view.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);

// -------------------------------------------------------------------------
// Load activity configuration
// -------------------------------------------------------------------------
$cfg = $DB->get_record('readingassessment_post_cfg', ['cmid' => $cmid]);
if (!$cfg) {
    $PAGE->set_title('Reading Assessment — Not Configured');
    $PAGE->set_heading($course->fullname);
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        'This reading assessment has not been configured yet. Please ask your teacher to set it up.',
        'warning'
    );
    if (has_capability('mod/readingassessment:editcontent', $context)) {
        echo '<a href="' . (new moodle_url('/mod/readingassessment/post_edit.php', ['cmid' => $cmid]))->out() . '" class="btn btn-primary mt-2">Configure Assessment Now</a>';
    }
    echo $OUTPUT->footer();
    exit;
}

// -------------------------------------------------------------------------
// Check Attempts Allowed limit
// -------------------------------------------------------------------------
$readingassessment = $DB->get_record('readingassessment', ['id' => $cm->instance]);
$attempts_allowed = (int)($readingassessment->attempts_allowed ?? 0);

$student_attempts = $DB->get_records('readingassessment_post_att', ['cfgid' => $cfg->id, 'userid' => $USER->id], 'timecompleted DESC');
$attempts_count = count($student_attempts);

if ($attempts_allowed > 0 && $attempts_count >= $attempts_allowed && !has_capability('mod/readingassessment:viewallresults', $context)) {
    $PAGE->set_title($cfg->passage_title ?: 'Reading Assessment');
    $PAGE->set_heading($course->fullname);
    echo $OUTPUT->header();
    
    echo '<div class="container-fluid mt-4 text-center">';
    echo $OUTPUT->notification('You have reached the maximum number of attempts allowed for this activity (Limit: ' . $attempts_allowed . ').', 'error');
    
    echo '<h4 class="mt-4 mb-3">Your Previous Attempts</h4>';
    echo '<div class="list-group mt-3 mx-auto shadow-sm text-left" style="max-width: 600px;">';
    foreach ($student_attempts as $att) {
        $date = userdate($att->timecompleted);
        $url = new moodle_url('/mod/readingassessment/post_results.php', ['cmid' => $cmid, 'attid' => $att->id]);
        echo '<a href="' . $url . '" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">';
        echo '<span><strong>Attempt</strong> — submitted on ' . $date . '</span>';
        echo '<span class="badge badge-primary badge-pill px-3 py-2">Review</span>';
        echo '</a>';
    }
    echo '</div>';
    echo '</div>';
    
    echo $OUTPUT->footer();
    exit;
}


$PAGE->set_title($cfg->passage_title ?: 'Reading Assessment');
$PAGE->set_heading($course->fullname);

// URLs
$submit_url = (new moodle_url('/mod/readingassessment/post_submit.php'))->out(false);
$coach_url  = (new moodle_url('/mod/readingassessment/post_coach.php'))->out(false);

// v536 public base URL — used for CSS and scripts
$v536_base  = (new moodle_url('/mod/readingassessment/v536/public/'))->out(false);
// Full absolute URL for pcm-processor.js — needed for AudioWorklet.addModule shim
$pcm_url    = (new moodle_url('/mod/readingassessment/v536/public/pcm-processor.js'))->out(false);

// JSON-encoded values for inline JS
$passage_text_json  = json_encode($cfg->passage_text ?? '');
$questions_json     = $cfg->questions_json ?? '[]';
$tts_personality_j  = json_encode($cfg->tts_personality ?? '');
$tts_voice_j        = json_encode($cfg->tts_voice ?? 'alloy');
$mastery_threshold  = (int)($cfg->mastery_threshold ?? 80);

// -------------------------------------------------------------------------
// Page output starts here
// -------------------------------------------------------------------------
// Load v536 CSS via header (CSS can go in head, only scripts need to be deferred)
$PAGE->requires->css(new moodle_url('/mod/readingassessment/v536/public/style.css'));

echo $OUTPUT->header();
?>

<div class="container-fluid mt-3 pt-assessment-wrap">

  <!-- ===== Header ===== -->
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-0"><?php echo s($cfg->passage_title ?: 'Reading Assessment'); ?></h3>
      <?php if (!empty($cfg->passage_instructions)): ?>
        <p class="text-muted mb-0 mt-1"><?php echo format_text($cfg->passage_instructions, FORMAT_HTML); ?></p>
      <?php endif; ?>
    </div>
    <div id="pt-status" class="badge badge-secondary p-2" style="font-size:14px;">Ready</div>
  </div>

  <!-- ===== v536 ENGINE SHELL =====
       All required v536 DOM elements must exist BEFORE app.js is loaded,
       because app.js runs: window.originalStory = els.story.textContent
       at the top level (not inside DOMContentLoaded).
  -->
  <main class="shell">
    <header class="topbar">
      <div>
        <div class="eyebrow">Post-Test Assessment</div>
        <h1><?php echo s($cfg->passage_title ?? 'Reading Assessment'); ?></h1>
      </div>
      <div class="top-actions">
        <!-- Locale selector — hidden but required by app.js -->
        <label class="locale-label" hidden>Language
          <select id="locale">
            <option value="en-US" selected>English (PH)</option>
            <option value="en-US">English (US)</option>
          </select>
        </label>
        <div class="top-metrics">
          <div class="metric"><span>TIME</span><strong id="timer">00:00</strong></div>
          <div class="metric"><span>STORY WORDS</span><strong id="storyWordCount">0</strong></div>
        </div>
      </div>
    </header>

    <div class="reader-grid">
      <!-- LEFT: Reading passage (content rendered here — app.js reads this via #story) -->
      <section class="reading-pane">
        <div class="pane-heading">Reading Passage</div>
        <div id="story" class="story"><?php echo s($cfg->passage_text ?? ''); ?></div>
        <div id="miscueStrip" class="miscue-strip"></div>
      </section>

      <!-- RIGHT: Live transcript -->
      <section class="reading-pane">
        <div class="pane-heading">Live Transcript</div>
        <div id="transcript" class="transcript"></div>
        <div id="transcriptNotice" class="transcript-notice" hidden></div>
      </section>
    </div>

    <!-- Bottom row — performance metrics and controls -->
    <div class="bottom-row">
      <!-- Added hint and mic meter here to mimic external.php visual feedback -->
      <div class="reader-toolbar mb-3 d-flex align-items-center">
        <div class="toolbar-status mr-4">
          <span class="dot" id="vadDot" style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#ccc;margin-right:8px;"></span>
          <span id="hint" class="font-weight-bold">Ready. Press Start and read the passage.</span>
        </div>
        <div class="mic-meter" aria-label="Microphone activity" style="flex-grow:1;height:10px;background:#eee;border-radius:5px;overflow:hidden;"><div id="meterBar" style="height:100%;background:#28a745;width:0%;"></div></div>
      </div>

      <div class="performance-metrics">
        <div class="performance-card"><span>WPM</span><strong id="wpm">—</strong></div>
        <div class="performance-card"><span>Accuracy</span><strong id="readingAccuracy">—</strong></div>
        <div class="performance-card"><span>Status</span><strong id="statusPill">Idle</strong></div>
      </div>

      <!-- Our custom recording buttons (AMD module proxies these to v536 internal buttons) -->
      <div class="action-buttons mt-3" id="pt-recording-controls">
        <button id="pt-start-btn" class="btn btn-success btn-lg">🎙 Start Reading</button>
        <button id="pt-stop-btn"  class="btn btn-danger btn-lg" disabled>⏹ Stop</button>
        <button id="pt-retry-btn" class="btn btn-outline-secondary" disabled>🔄 Retry</button>
      </div>

      <!-- v536 native control buttons (hidden — AMD module clicks these programmatically) -->
      <div style="display:none;" aria-hidden="true">
        <button id="startBtn">start</button>
        <button id="stopBtn">stop</button>
        <button id="resetBtn">reset</button>
        <button id="restartBtn">restart</button>
        <button id="diagBtn">diag</button>
        <button id="editStoryBtn">edit</button>
        <input  id="storyEditor" type="hidden">
      </div>

      <!-- v536 required display elements (hidden ones are functional requirements) -->
      <div style="display:none;" aria-hidden="true">
        <div id="liveBadge"></div>

        <div id="storyWordCount"></div>
        <div id="wordBadge"></div>
      </div>

      <!-- Diagnostics modal (required by app.js) -->
      <dialog id="diagModal">
        <button id="closeDiagBtn">Close</button>
        <div id="diagContent"></div>
      </dialog>

      <!-- Tooltip (required by app.js hover logic) -->
      <div id="tooltip" class="word-tooltip" hidden></div>
    </div>
  </main>
  <!-- ===== END v536 SHELL ===== -->

  <!-- ===== COMPREHENSION QUESTIONNAIRE (shown after recording stops) ===== -->
  <div id="pt-questionnaire" class="card shadow-sm mt-4" style="display:none; border: 2px solid #0d6efd; border-radius: 8px;">
    <div class="card-header bg-primary text-white">
      <h4 class="mb-0">📝 Comprehension Test</h4>
    </div>
    <div class="card-body">
      <p class="text-muted">Answer the following questions about what you just read.</p>
      <div id="pt-questions-container"></div>
    </div>
    <div class="card-footer text-right">
        <button id="pt-submit-btn" class="btn btn-success btn-lg font-weight-bold shadow">✅ Submit Assessment</button>
        <span id="pt-submit-status" class="text-muted small ml-3"></span>
    </div>
  </div>

  <!-- ===== RESULTS CARD (shown after submission) ===== -->
  <div id="pt-results-card" class="card mt-4 border-success" style="display:none;">
    <div class="card-header bg-success text-white"><h5 class="mb-0">🎉 Your Results</h5></div>
    <div class="card-body">
      <div class="row text-center">
        <div class="col-4"><h4 id="pt-res-accuracy">—</h4><small class="text-muted">Reading Accuracy</small></div>
        <div class="col-4"><h4 id="pt-res-wpm">—</h4><small class="text-muted">Words per Minute</small></div>
        <div class="col-4"><h4 id="pt-res-comp">—</h4><small class="text-muted">Comprehension</small></div>
      </div>
    </div>
  </div>

  <!-- ===== COACH PROMPT MODAL ===== -->
  <div class="modal fade" id="pt-coach-prompt-modal" tabindex="-1" role="dialog" aria-modal="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header bg-info text-white">
          <h5 class="modal-title">🎤 Pronunciation Practice</h5>
        </div>
        <div class="modal-body">
          <p>You had some pronunciation challenges. Would you like to practice those words now with your AI coach?</p>
          <div id="pt-mispr-words" class="mb-2"></div>
        </div>
        <div class="modal-footer">
          <button type="button" id="pt-coach-start-btn" class="btn btn-primary btn-lg">🎯 Practice Now</button>
          <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Skip</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Coach modal mount point -->
  <div id="pt-coach-modal-mount"></div>

</div><!-- /.pt-assessment-wrap -->

<style>
.pt-assessment-wrap { max-width: 1200px; margin: 0 auto; }
#pt-recording-controls { display: flex; gap: 12px; flex-wrap: wrap; }
#pt-recording-controls button { min-width: 130px; }
</style>

<?php
// =========================================================================
// SCRIPTS — loaded at bottom of body so all DOM elements exist first.
// assessment-core.js must come before app.js (app.js uses window.PronunciationAssessment).
// =========================================================================

// Step 1: AudioWorklet shim — rewrites the pcm-processor.js relative path
// to an absolute URL before app.js runs its addModule call.
// app.js uses: audioContext.audioWorklet.addModule("v536/public/pcm-processor.js?...")
// which resolves relative to the Moodle page URL (wrong) — we fix it here.
$pcm_url_j = json_encode($pcm_url);
echo '<script>
(function() {
    var PCM_URL = ' . $pcm_url_j . ';
    // Wrap AudioContext constructor to intercept audioWorklet.addModule calls
    var NativeAC = window.AudioContext || window.webkitAudioContext;
    if (!NativeAC) return;
    var OrigProto = NativeAC.prototype;
    var origAddModule = null;
    // We use a getter on the audioWorklet property to intercept
    function patchWorklet(worklet) {
        if (worklet && !worklet.__pcmPatched) {
            worklet.__pcmPatched = true;
            origAddModule = worklet.addModule.bind(worklet);
            worklet.addModule = function(url, options) {
                if (typeof url === "string" && url.indexOf("pcm-processor") !== -1) {
                    url = PCM_URL;
                }
                return origAddModule(url, options);
            };
        }
        return worklet;
    }
    // Patch AudioContext prototype getter for audioWorklet
    var descriptor = Object.getOwnPropertyDescriptor(OrigProto, "audioWorklet");
    if (descriptor && descriptor.get) {
        Object.defineProperty(OrigProto, "audioWorklet", {
            get: function() { return patchWorklet(descriptor.get.call(this)); },
            configurable: true
        });
    }
})();
</script>';

// Step 2: Global config for AMD modules
echo '<script>
window.PT_TTS_PERSONALITY  = ' . $tts_personality_j . ';
window.PT_TTS_VOICE        = ' . $tts_voice_j . ';
window.PT_MASTERY_THRESHOLD = ' . $mastery_threshold . ';
window.PT_CMID             = ' . (int)$cmid . ';
window.PT_SESSKEY          = ' . json_encode(sesskey()) . ';
window.PT_SUBMIT_URL       = ' . json_encode($submit_url) . ';
window.PT_COACH_URL        = ' . json_encode($coach_url) . ';
window.PT_QUESTIONS        = ' . $questions_json . ';
window.PT_PASSAGE_TEXT     = ' . $passage_text_json . ';
</script>';

// Step 3: Load v536 engine scripts (AFTER DOM so els.story.textContent is accessible)
echo '<script src="' . s($v536_base) . 'assessment-core.js"></script>';
echo '<script src="' . s($v536_base) . 'app.js?v=4.5.43"></script>';

// Step 4: Load AMD modules and wire everything up

$PAGE->requires->js_amd_inline("
require(['mod_readingassessment/post_questionnaire', 'mod_readingassessment/post_recorder', 'mod_readingassessment/post_coach'],
function(Questionnaire, Recorder, Coach) {

    // Render comprehension questionnaire
    var questions = window.PT_QUESTIONS || [];
    if (questions.length) {
        Questionnaire.init('pt-questions-container', questions);
    } else {
        var el = document.getElementById('pt-questions-container');
        if (el) el.innerHTML = '<p class=\"text-muted\">No comprehension questions for this assessment.</p>';
    }

    // Init recorder (wires our buttons to v536 and handles submission)
    Recorder.init({
        cmid:       window.PT_CMID,
        sesskey:    window.PT_SESSKEY,
        passageText: window.PT_PASSAGE_TEXT,
        locale:     'en-US',
        submitUrl:  window.PT_SUBMIT_URL,
        coachUrl:   window.PT_COACH_URL,
        getAnswers: function() {
            return Questionnaire.getAnswers('pt-questions-container', window.PT_QUESTIONS);
        }
    });

    // Listen for submission success
    window.addEventListener('readingAssessmentSubmitted', function(e) {
        var d = e.detail;

        // Show results card
        var rc = document.getElementById('pt-results-card');
        if (rc) rc.style.display = 'block';
        var accEl  = document.getElementById('pt-res-accuracy');
        var wpmEl  = document.getElementById('pt-res-wpm');
        var compEl = document.getElementById('pt-res-comp');
        if (accEl)  accEl.textContent  = (parseFloat(d.accuracy) || 0).toFixed(1) + '%';
        if (wpmEl)  wpmEl.textContent  = (parseFloat(d.wpm) || 0).toFixed(0);
        if (compEl) compEl.textContent = (parseFloat(d.comprehension) || 0).toFixed(1) + '%';

        // Show coach prompt if mispronounced words exist
        var words = d.mispronounced_words || [];
        if (words.length > 0) {
            var strip = document.getElementById('pt-mispr-words');
            if (strip) {
                strip.innerHTML = '<p class=\"mb-1\">Challenged words:</p>' +
                    words.map(function(w) {
                        return '<span class=\"badge badge-warning mr-1 p-2\" style=\"font-size:14px;\">' +
                            w.replace(/</g,'&lt;') + '</span>';
                    }).join('');
            }
            var modal = document.getElementById('pt-coach-prompt-modal');
            if (modal) {
                modal.classList.add('show');
                modal.style.display = 'block';
                modal.style.background = 'rgba(0,0,0,0.5)';
            }
            var practiceBtn = document.getElementById('pt-coach-start-btn');
            if (practiceBtn) {
                practiceBtn.onclick = function() {
                    if (modal) {
                        modal.classList.remove('show');
                        modal.style.display = 'none';
                    }
                    Coach.init({
                        coachUrl:         window.PT_COACH_URL,
                        attId:            d.attemptId,
                        words:            words,
                        masteryThreshold: window.PT_MASTERY_THRESHOLD
                    });
                };
            }
            // Wire up the skip button too
            var skipBtn = modal ? modal.querySelector('[data-dismiss=\"modal\"]') : null;
            if (skipBtn) {
                skipBtn.onclick = function() {
                    modal.classList.remove('show');
                    modal.style.display = 'none';
                };
            }
        }
    });
});
");

echo $OUTPUT->footer();
?>
