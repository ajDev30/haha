<?php
/**
 * post_edit.php — Teacher authoring interface for the post-test Reading Assessment.
 *
 * Allows teachers to:
 *  - Set passage title, text and instructions
 *  - Build a comprehension questionnaire (multiple choice, T/F, enumeration, matching, description)
 *  - Configure OpenAI TTS personality and voice
 *  - Set mastery threshold
 *
 * This file is completely separate from external_edit.php (pre-assessment).
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$cmid = required_param('cmid', PARAM_INT);

$cm      = get_coursemodule_from_id('readingassessment', $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cmid);

require_login($course, true, $cm);
require_capability('mod/readingassessment:editcontent', $context);

$PAGE->set_url(new moodle_url('/mod/readingassessment/post_edit.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_title(get_string('editposttest', 'readingassessment'));
$PAGE->set_heading($course->fullname);

// -------------------------------------------------------------------------
// Default TTS personality
// -------------------------------------------------------------------------
define('PT_DEFAULT_TTS_PERSONALITY',
    "Accent/Affect: Warm, refined, and gently instructive, reminiscent of a friendly art instructor.\n\n" .
    "Tone: Calm, encouraging, and articulate, clearly describing each step with patience.\n\n" .
    "Pacing: Slow and deliberate, pausing often to allow the listener to follow instructions comfortably.\n\n" .
    "Emotion: Cheerful, supportive, and pleasantly enthusiastic; convey genuine enjoyment and appreciation of art.\n\n" .
    "Pronunciation: Clearly articulate artistic terminology with gentle emphasis.\n\n" .
    "Personality Affect: Friendly and approachable with a hint of sophistication; speak confidently and reassuringly."
);

// -------------------------------------------------------------------------
// Handle POST — Save configuration
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && data_submitted()) {
    require_sesskey();

    $passage_title        = optional_param('passage_title', '', PARAM_TEXT);
    $passage_text         = optional_param('passage_text', '', PARAM_RAW);
    $passage_instructions = optional_param('passage_instructions', '', PARAM_RAW);
    $tts_personality      = optional_param('tts_personality', PT_DEFAULT_TTS_PERSONALITY, PARAM_RAW);
    $tts_voice            = optional_param('tts_voice', 'alloy', PARAM_ALPHA);
    $mastery_threshold    = optional_param('mastery_threshold', 80, PARAM_INT);
    $questions_json_raw   = optional_param('questions_json', '[]', PARAM_RAW);

    // Validate questions JSON
    $questions_decoded = @json_decode($questions_json_raw, true);
    if (!is_array($questions_decoded)) {
        $questions_json_raw = '[]';
    }

    // Clamp threshold 50–100
    $mastery_threshold = max(50, min(100, $mastery_threshold));

    $existing = $DB->get_record('readingassessment_post_cfg', ['cmid' => $cmid]);

    $record = new stdClass();
    $record->cmid                 = $cmid;
    $record->passage_title        = $passage_title;
    $record->passage_text         = $passage_text;
    $record->passage_instructions = $passage_instructions;
    $record->questions_json       = $questions_json_raw;
    $record->tts_personality      = $tts_personality;
    $record->tts_voice            = $tts_voice;
    $record->mastery_threshold    = $mastery_threshold;
    $record->timemodified         = time();

    if ($existing) {
        $record->id = $existing->id;
        $DB->update_record('readingassessment_post_cfg', $record);
    } else {
        $record->timecreated = time();
        $DB->insert_record('readingassessment_post_cfg', $record);
    }

    redirect(
        new moodle_url('/mod/readingassessment/post_edit.php', ['cmid' => $cmid]),
        get_string('changessaved', 'moodle'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// -------------------------------------------------------------------------
// Load existing config
// -------------------------------------------------------------------------
$cfg = $DB->get_record('readingassessment_post_cfg', ['cmid' => $cmid]);

$passage_title        = $cfg->passage_title        ?? '';
$passage_text         = $cfg->passage_text         ?? '';
$passage_instructions = $cfg->passage_instructions ?? '';
$tts_personality      = $cfg->tts_personality      ?? PT_DEFAULT_TTS_PERSONALITY;
$tts_voice            = $cfg->tts_voice            ?? 'alloy';
$mastery_threshold    = $cfg->mastery_threshold    ?? 80;
$questions_json       = $cfg->questions_json       ?? '[]';

echo $OUTPUT->header();
?>

<style>
.pt-edit-section { margin-bottom: 2rem; }
.pt-edit-section h4 { border-bottom: 2px solid #dee2e6; padding-bottom: .5rem; margin-bottom: 1rem; }
.q-card { border: 1px solid #dee2e6; border-radius: 8px; margin-bottom: 1rem; background: #fff; }
.q-card .q-card-header { background: #f8f9fa; padding: .6rem 1rem; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; cursor: grab; }
.q-card .q-card-body { padding: 1rem; }
.q-type-badge { font-size: 11px; padding: 2px 8px; border-radius: 12px; font-weight: 700; }
#questions-container .sortable-ghost { opacity: 0.4; }
</style>

<div class="container-fluid mt-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>✏️ <?php echo get_string('editposttest', 'readingassessment'); ?></h3>
    <a href="<?php echo (new moodle_url('/mod/readingassessment/post_manage.php', ['cmid' => $cmid]))->out(); ?>" class="btn btn-outline-secondary btn-sm">← Back to Dashboard</a>
  </div>

  <form method="POST" action="<?php echo (new moodle_url('/mod/readingassessment/post_edit.php', ['cmid' => $cmid]))->out(false); ?>">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <input type="hidden" name="questions_json" id="questions_json_field" value="<?php echo s($questions_json); ?>">

    <!-- ===== PASSAGE ===== -->
    <div class="card pt-edit-section">
      <div class="card-header bg-primary text-white"><h5 class="mb-0">📖 Reading Passage</h5></div>
      <div class="card-body">
        <div class="form-group">
          <label><strong>Passage Title</strong></label>
          <input type="text" name="passage_title" class="form-control" value="<?php echo s($passage_title); ?>" placeholder="e.g. The Little Red Hen" required>
        </div>
        <div class="form-group">
          <label><strong>Instructions / Introduction</strong> <small class="text-muted">(optional, shown above passage)</small></label>
          <textarea name="passage_instructions" class="form-control" rows="2"><?php echo s($passage_instructions); ?></textarea>
        </div>
        <div class="form-group">
          <label><strong>Passage Text</strong> <small class="text-muted">(this is the exact text students will read aloud)</small></label>
          <textarea name="passage_text" class="form-control" rows="8" required><?php echo s($passage_text); ?></textarea>
          <small class="form-text text-muted">Word count: <span id="wc">0</span></small>
        </div>
      </div>
    </div>

    <!-- ===== QUESTIONNAIRE ===== -->
    <div class="card pt-edit-section">
      <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">📝 Comprehension Questionnaire</h5>
        <div>
          <select id="add-q-type" class="form-select form-select-sm d-inline-block" style="width:auto">
            <option value="multiple_choice">Multiple Choice</option>
            <option value="true_false">True / False</option>
            <option value="enumeration">Enumeration</option>
            <option value="matching">Matching</option>
            <option value="description">Description / Instruction</option>
          </select>
          <button type="button" id="add-q-btn" class="btn btn-sm btn-light ml-2">➕ Add Question</button>
        </div>
      </div>
      <div class="card-body">
        <p class="text-muted small">Drag questions to reorder. Click 🗑 to delete.</p>
        <div id="questions-container"></div>
      </div>
    </div>

    <!-- ===== TTS SETTINGS ===== -->
    <div class="card pt-edit-section">
      <div class="card-header bg-secondary text-white"><h5 class="mb-0">🔊 Coach TTS Settings</h5></div>
      <div class="card-body">
        <div class="form-group">
          <label><strong>OpenAI Voice</strong></label>
          <select name="tts_voice" class="form-select" style="max-width:250px">
            <?php foreach (['alloy','echo','fable','onyx','nova','shimmer'] as $v): ?>
            <option value="<?php echo $v; ?>" <?php echo ($tts_voice === $v) ? 'selected' : ''; ?>><?php echo ucfirst($v); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label><strong>TTS Personality Prompt</strong></label>
          <textarea name="tts_personality" class="form-control" rows="6"><?php echo s($tts_personality); ?></textarea>
          <small class="form-text text-muted">This instruction is sent to OpenAI TTS to shape the coach's voice and style.</small>
        </div>
        <div class="form-group">
          <label><strong>Mastery Threshold (%)</strong> <small class="text-muted">(default 80)</small></label>
          <input type="number" name="mastery_threshold" class="form-control" value="<?php echo (int)$mastery_threshold; ?>" min="50" max="100" style="max-width:120px">
        </div>
      </div>
    </div>

    <div class="mb-4">
      <button type="submit" class="btn btn-success btn-lg">💾 Save Assessment</button>
      <a href="<?php echo (new moodle_url('/mod/readingassessment/post_manage.php', ['cmid' => $cmid]))->out(); ?>" class="btn btn-outline-secondary ml-2">Cancel</a>
    </div>
  </form>
</div>

<script>
// -------------------------------------------------------------------------
// Question builder — pure vanilla JS (no AMD needed here)
// -------------------------------------------------------------------------
let questions = [];
try { questions = JSON.parse(<?php echo json_encode($questions_json); ?>) || []; } catch(e) {}

const QTYPES = {
    multiple_choice: { label: 'Multiple Choice', color: 'primary' },
    true_false:      { label: 'True / False',    color: 'success' },
    enumeration:     { label: 'Enumeration',     color: 'warning' },
    matching:        { label: 'Matching',         color: 'info' },
    description:     { label: 'Description',      color: 'secondary' },
};

function renderAll() {
    const container = document.getElementById('questions-container');
    container.innerHTML = '';
    if (questions.length === 0) {
        container.innerHTML = '<p class="text-muted text-center">No questions yet. Use the Add Question button above.</p>';
    }
    questions.forEach((q, idx) => renderQuestion(q, idx, container));
    syncJson();
}

function renderQuestion(q, idx, container) {
    const typeInfo = QTYPES[q.type] || { label: q.type, color: 'dark' };
    const card = document.createElement('div');
    card.className = 'q-card';
    card.dataset.idx = idx;

    let bodyHtml = `
        <div class="form-group">
            <label class="font-weight-bold">${q.type === 'description' ? 'Instruction / Description Text' : 'Question Text'}</label>
            <textarea class="form-control q-text" rows="2" placeholder="Enter text here...">${escHtml(q.qtext || '')}</textarea>
        </div>`;

    if (q.type === 'multiple_choice') {
        const opts = q.options || [{correct: true, text: ''}, {correct: false, text: ''}];
        bodyHtml += `<div class="p-3 bg-light border rounded mb-2">
            <div style="margin-bottom: 8px; font-weight: 600; font-size: 0.85rem;">Choices (Select the correct one):</div>
            <div class="q-mc-options-container" style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px;">
                ${opts.map((opt, oidx) => `
                    <div class="q-mc-option-row" style="display: flex; gap: 8px; align-items: center;">
                        <input type="radio" name="mc_correct_${idx}" class="q-mc-correct" value="${oidx}" ${opt.correct ? 'checked' : ''}>
                        <input type="text" class="form-control q-mc-text" value="${escHtml(opt.text)}" placeholder="Choice text" style="flex: 1;">
                        <button type="button" class="btn btn-sm btn-outline-danger rm-mc-opt-btn" data-oidx="${oidx}">✖</button>
                    </div>
                `).join('')}
            </div>
            <div style="display: flex; justify-content: space-between;">
                <button type="button" class="btn btn-sm btn-outline-primary add-mc-opt-btn">➕ Add Choice</button>
                <label style="font-weight: 600; font-size: 0.85rem; cursor: pointer;">
                    <input type="checkbox" class="q-mc-shuffle" ${q.shuffle ? 'checked' : ''}> Shuffle Choices
                </label>
            </div>
        </div>`;
    } else if (q.type === 'true_false') {
        bodyHtml += `<label class="font-weight-bold">Correct Answer</label>
        <select class="custom-select q-answer">
            <option value="true" ${q.answer === 'true' ? 'selected' : ''}>True</option>
            <option value="false" ${q.answer === 'false' ? 'selected' : ''}>False</option>
        </select>`;
    } else if (q.type === 'enumeration') {
        bodyHtml += `<div class="p-3 bg-light border rounded mb-2">
            <label class="font-weight-bold">Accepted Answers</label>
            <p class="small text-muted mb-2">Type one accepted answer per line. The student will have a text box to list their answers.</p>
            <textarea class="form-control q-enum" rows="4" placeholder="Apple\nBanana\nOrange">${escHtml((q.answers || []).join('\n'))}</textarea>
        </div>`;
    } else if (q.type === 'matching') {
        const pairs = q.pairs || [{ left: '', right: '' }];
        bodyHtml += `<div class="p-3 bg-light border rounded mb-2">
            <label class="font-weight-bold mb-1">Matching Pairs</label>
            <p class="small text-muted mb-2">The left items will be listed, and students will select the matching right item from a dropdown.</p>
            <div class="q-pairs">`;
        pairs.forEach((p, pi) => {
            bodyHtml += `<div class="input-group mb-2 shadow-sm">
                <input type="text" class="form-control q-pair-left" placeholder="Left item (e.g. Term)" value="${escHtml(p.left || '')}">
                <div class="input-group-prepend input-group-append"><span class="input-group-text bg-white border-0">→</span></div>
                <input type="text" class="form-control q-pair-right" placeholder="Right item (e.g. Definition)" value="${escHtml(p.right || '')}">
                <div class="input-group-append">
                    <button type="button" class="btn btn-danger rm-pair-btn" title="Remove Pair">✖</button>
                </div>
            </div>`;
        });
        bodyHtml += `</div><button type="button" class="btn btn-sm btn-primary add-pair-btn mt-2">+ Add Another Pair</button></div>`;
    }

    if (q.type !== 'description') {
        bodyHtml += `<div class="form-group mt-2">
            <label class="font-weight-bold">Points</label>
            <input type="number" class="form-control q-points" value="${parseInt(q.points) || 1}" min="0" style="max-width:100px">
        </div>`;
    }

    card.innerHTML = `
        <div class="q-card-header">
            <span>
                <span class="q-type-badge badge badge-${typeInfo.color}">${typeInfo.label}</span>
                <small class="ml-2 text-muted">Question ${idx + 1}</small>
            </span>
            <div>
                <button type="button" class="btn btn-sm btn-link move-up-btn" title="Move up">↑</button>
                <button type="button" class="btn btn-sm btn-link move-down-btn" title="Move down">↓</button>
                <button type="button" class="btn btn-sm btn-danger delete-q-btn" title="Delete">🗑</button>
            </div>
        </div>
        <div class="q-card-body">${bodyHtml}</div>`;

    // Event listeners
    card.querySelector('.delete-q-btn').addEventListener('click', () => { questions.splice(idx, 1); renderAll(); });
    card.querySelector('.move-up-btn')?.addEventListener('click', () => { if (idx > 0) { [questions[idx], questions[idx-1]] = [questions[idx-1], questions[idx]]; renderAll(); } });
    card.querySelector('.move-down-btn')?.addEventListener('click', () => { if (idx < questions.length-1) { [questions[idx], questions[idx+1]] = [questions[idx+1], questions[idx]]; renderAll(); } });

    // Live sync on input
    card.addEventListener('input', () => syncFromCard(card, idx));
    card.addEventListener('change', () => syncFromCard(card, idx));

    if (q.type === 'multiple_choice') {
        card.querySelector('.add-mc-opt-btn')?.addEventListener('click', () => {
            questions[idx].options = questions[idx].options || [];
            questions[idx].options.push({ correct: false, text: '' });
            renderAll();
        });
        card.querySelectorAll('.rm-mc-opt-btn').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                const oidx = parseInt(e.target.dataset.oidx);
                questions[idx].options.splice(oidx, 1);
                if (questions[idx].options.length > 0 && !questions[idx].options.some(o => o.correct)) {
                    questions[idx].options[0].correct = true;
                }
                renderAll();
            });
        });
    }

    if (q.type === 'matching') {
        card.querySelector('.add-pair-btn')?.addEventListener('click', () => {
            questions[idx].pairs = questions[idx].pairs || [];
            questions[idx].pairs.push({ left: '', right: '' });
            renderAll();
        });
        card.querySelectorAll('.rm-pair-btn').forEach((btn, pi) => {
            btn.addEventListener('click', () => {
                questions[idx].pairs.splice(pi, 1);
                renderAll();
            });
        });
    }

    container.appendChild(card);
}

function syncFromCard(card, idx) {
    const q = questions[idx];
    const textEl = card.querySelector('.q-text');
    if (textEl) q.qtext = textEl.value;

    if (q.type === 'multiple_choice') {
        const optionRows = card.querySelectorAll('.q-mc-option-row');
        q.options = [...optionRows].map((row) => ({
            correct: row.querySelector('.q-mc-correct').checked,
            text: row.querySelector('.q-mc-text').value
        }));
        const shuffleEl = card.querySelector('.q-mc-shuffle');
        if (shuffleEl) q.shuffle = shuffleEl.checked;
    } else if (q.type === 'true_false') {
        q.answer = card.querySelector('.q-answer')?.value || 'true';
    } else if (q.type === 'enumeration') {
        q.answers = (card.querySelector('.q-enum')?.value || '').split('\n').map(l => l.trim()).filter(Boolean);
    } else if (q.type === 'matching') {
        const lefts = card.querySelectorAll('.q-pair-left');
        const rights = card.querySelectorAll('.q-pair-right');
        q.pairs = [...lefts].map((l, i) => ({ left: l.value, right: rights[i]?.value || '' }));
    }

    const pointsEl = card.querySelector('.q-points');
    if (pointsEl) q.points = parseInt(pointsEl.value) || 1;

    syncJson();
}

function syncJson() {
    document.getElementById('questions_json_field').value = JSON.stringify(questions);
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

// Add question button
document.getElementById('add-q-btn').addEventListener('click', () => {
    const type = document.getElementById('add-q-type').value;
    const defaults = {
        multiple_choice: { type, qtext: '', options: [{ correct: true, text: '' }, { correct: false, text: '' }], points: 1 },
        true_false:      { type, qtext: '', answer: 'true', points: 1 },
        enumeration:     { type, qtext: '', answers: [], points: 1 },
        matching:        { type, qtext: '', pairs: [{ left: '', right: '' }], points: 1 },
        description:     { type, qtext: '', points: 0 },
    };
    questions.push(defaults[type] || { type, qtext: '' });
    renderAll();
});

// Word count
const passageEl = document.querySelector('textarea[name="passage_text"]');
const wcEl = document.getElementById('wc');
function updateWc() { wcEl.textContent = passageEl.value.trim().split(/\s+/).filter(Boolean).length; }
passageEl.addEventListener('input', updateWc);
updateWc();

// Initial render
renderAll();
</script>

<?php echo $OUTPUT->footer(); ?>
