<?php
/**
 * post_results.php — Detailed view of a single student's post-test attempt.
 *
 * Shows the teacher:
 *  - Reading scores (accuracy, WPM, comprehension)
 *  - Transcript markup with Azure miscue highlighting
 *  - Questionnaire responses vs correct answers
 *  - Pronunciation coach practice history
 *
 * Security: requires viewallresults capability or own attempt ownership.
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$attid = required_param('attid', PARAM_INT);
$cmid  = required_param('cmid', PARAM_INT);

$cm      = get_coursemodule_from_id('readingassessment', $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cmid);

require_login($course, true, $cm);

$attempt = $DB->get_record_sql("
    SELECT a.*, u.firstname, u.lastname, u.email
    FROM {readingassessment_post_att} a
    JOIN {user} u ON u.id = a.userid
    WHERE a.id = :attid
", ['attid' => $attid]);

if (!$attempt) {
    throw new moodle_exception('invalidattempt', 'readingassessment');
}

// Security: teacher sees all, student sees only own
$is_teacher = has_capability('mod/readingassessment:viewallresults', $context);
if (!$is_teacher && $attempt->userid != $USER->id) {
    throw new moodle_exception('nopermissions', 'error', '', 'View results');
}

$cfg = $DB->get_record('readingassessment_post_cfg', ['id' => $attempt->cfgid]);
$questions = @json_decode($cfg->questions_json ?? '[]', true) ?: [];

// Load questionnaire responses
$responses = $DB->get_records('readingassessment_post_resp', ['attid' => $attid], 'questionid ASC');

// Load coach practice history
$coach_records = $DB->get_records('readingassessment_post_coach', ['attid' => $attid], 'timecreated ASC');

$PAGE->set_url(new moodle_url('/mod/readingassessment/post_results.php', ['attid' => $attid, 'cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_title('Attempt Results');
$PAGE->set_heading($course->fullname);

// Include v536 CSS for transcript markup rendering
$v536_base = (new moodle_url('/mod/readingassessment/v536/public/'))->out(false);

echo $OUTPUT->header();
echo '<link rel="stylesheet" href="' . s($v536_base) . 'style.css">';
?>

<div class="container-fluid mt-3">

  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h3>📄 Attempt Detail</h3>
      <p class="text-muted mb-0">
        <strong><?php echo s($attempt->firstname . ' ' . $attempt->lastname); ?></strong>
        · <?php echo userdate($attempt->timecompleted, '%d %b %Y %H:%M'); ?>
      </p>
    </div>
    <a href="<?php echo (new moodle_url('/mod/readingassessment/post_manage.php', ['cmid' => $cmid]))->out(); ?>" class="btn btn-outline-secondary">← Back to Dashboard</a>
  </div>

  <!-- Score Summary -->
  <div class="row mb-4">
    <?php
      $scores = [
        ['label' => 'Reading Accuracy', 'value' => round($attempt->accuracy, 1) . '%', 'color' => $attempt->accuracy >= 90 ? 'success' : ($attempt->accuracy >= 75 ? 'warning' : 'danger')],
        ['label' => 'Words per Minute', 'value' => round($attempt->wpm, 0), 'color' => 'info'],
        ['label' => 'Comprehension',    'value' => round($attempt->comprehension, 1) . '%', 'color' => $attempt->comprehension >= 75 ? 'success' : ($attempt->comprehension >= 50 ? 'warning' : 'danger')],
        ['label' => 'Points Earned',    'value' => round($attempt->comp_earned, 1) . ' / ' . round($attempt->comp_total, 1), 'color' => 'primary'],
      ];
      foreach ($scores as $s):
    ?>
    <div class="col-md-3 col-6 mb-3">
      <div class="card text-center p-3 border-<?php echo $s['color']; ?>">
        <h3 class="text-<?php echo $s['color']; ?>"><?php echo $s['value']; ?></h3>
        <small class="text-muted"><?php echo $s['label']; ?></small>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Transcript Markup -->
  <?php if (!empty($attempt->transcript_markup)): ?>
  <div class="card mb-4">
    <div class="card-header"><strong>🗣 Transcript with Miscues</strong></div>
    <div class="card-body" style="font-size:18px; line-height:2.5;">
      <?php echo $attempt->transcript_markup; ?>
    </div>
    <!-- Legend (same as v536) -->
    <div class="legend px-3 pb-2">
      <div class="legend-item"><i class="legend-mis"></i> Mispronunciation</div>
      <div class="legend-item"><i class="legend-om"></i> Omission</div>
      <div class="legend-item"><i class="legend-sub"></i> Substitution</div>
      <div class="legend-item"><i class="legend-rep"></i> Repetition</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Questionnaire Responses -->
  <?php if (!empty($questions) && !empty($responses)): ?>
  <div class="card mb-4">
    <div class="card-header"><strong>📝 Questionnaire Results</strong></div>
    <div class="card-body">
      <?php
      $resp_map = [];
      foreach ($responses as $r) $resp_map[$r->questionid] = $r;
      $q_idx = 0;
      foreach ($questions as $qi => $q):
        if (($q['type'] ?? '') === 'description') { continue; }
        $resp = $resp_map[$qi] ?? null;
        $earned = $resp ? round($resp->earned, 2) : 0;
        $possible = $resp ? round($resp->possible, 2) : ($q['points'] ?? 1);
        $color = ($earned >= $possible) ? 'success' : (($earned > 0) ? 'warning' : 'danger');
        $q_idx++;
      ?>
      <div class="card mb-2 border-<?php echo $color; ?>">
        <div class="card-header d-flex justify-content-between">
          <span><strong>Q<?php echo $q_idx; ?>.</strong> <?php echo s($q['qtext'] ?? ''); ?></span>
          <span class="badge badge-<?php echo $color; ?>"><?php echo $earned . ' / ' . $possible; ?> pts</span>
        </div>
        <div class="card-body py-2">
          <?php
          $student_ans = $resp ? @json_decode($resp->answer_json, true) : null;
          echo '<p class="mb-1"><small class="text-muted">Student answered:</small> ' . s($student_ans ?? '—') . '</p>';

          // Show correct answer
          $correct = '';
          switch ($q['type']) {
              case 'multiple_choice':
                  foreach ($q['options'] ?? [] as $opt) {
                      if (is_array($opt) && !empty($opt['correct'])) { $correct = $opt['text'] ?? ''; break; }
                  }
                  break;
              case 'true_false':
                  $correct = $q['answer'] ?? 'true';
                  break;
              case 'enumeration':
                  $correct = implode(', ', $q['answers'] ?? []);
                  break;
              case 'matching':
                  $pairs = $q['pairs'] ?? [];
                  $correct = implode('; ', array_map(fn($p) => ($p['left'] ?? '') . ' → ' . ($p['right'] ?? ''), $pairs));
                  break;
          }
          if ($correct) echo '<p class="mb-0"><small class="text-success">Correct answer: ' . s($correct) . '</small></p>';
          ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Coach Practice History -->
  <?php if (!empty($coach_records)): ?>
  <div class="card mb-4">
    <div class="card-header"><strong>🎤 Pronunciation Coach History</strong></div>
    <div class="card-body">
      <?php
      $by_word = [];
      foreach ($coach_records as $cr) {
          $by_word[$cr->target_word][] = $cr;
      }
      foreach ($by_word as $word => $records):
        $last = end($records);
        $mastered = (bool)$last->mastered;
      ?>
      <div class="d-flex align-items-center mb-2 p-2 border rounded">
        <strong class="mr-3" style="min-width:100px; font-size:18px;"><?php echo s($word); ?></strong>
        <?php if ($mastered): ?>
          <span class="badge badge-success p-2">✅ Mastered</span>
        <?php else: ?>
          <span class="badge badge-warning p-2">⚠️ Needs Practice</span>
        <?php endif; ?>
        <span class="ml-3 text-muted small"><?php echo count($records); ?> attempt(s)</span>
        <span class="ml-auto text-muted small">Best score: <?php echo max(array_column($records, 'score')); ?>%</span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Raw Azure Data (Teacher only, collapsed) -->
  <?php if ($is_teacher && !empty($attempt->azure_json)): ?>
  <div class="card mb-4">
    <div class="card-header">
      <a data-toggle="collapse" href="#azure-raw">🔬 Raw Azure Evaluation (Teacher Only)</a>
    </div>
    <div id="azure-raw" class="collapse">
      <div class="card-body">
        <pre style="max-height:400px;overflow:auto;font-size:11px;background:#0b1020;color:#d9e1ff;padding:12px;border-radius:8px;"><?php echo s($attempt->azure_json); ?></pre>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
// Global tooltip for baked transcript markup
const staticTooltip = document.createElement('div');
staticTooltip.id = "global-static-tooltip";
staticTooltip.style.cssText = 'position: fixed; background: rgba(0,0,0,0.85); color: #fff; padding: 12px; border-radius: 8px; font-size: 0.95rem; z-index: 10000; pointer-events: none; display: none; box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-width: 350px; text-align: left; line-height: 1.4;';
document.body.appendChild(staticTooltip);

document.addEventListener('mouseover', function(e) {
    const token = e.target.closest('.transcript-token');
    if (!token || !token.getAttribute('data-tip-html')) return;
    staticTooltip.innerHTML = token.getAttribute('data-tip-html');
    staticTooltip.style.display = 'block';
});
document.addEventListener('mousemove', function(e) {
    if (staticTooltip.style.display === 'block') {
        const pad = 15;
        let left = e.clientX + pad;
        let top = e.clientY + pad;
        const rect = staticTooltip.getBoundingClientRect();
        if (left + rect.width > window.innerWidth - pad) left = e.clientX - rect.width - pad;
        if (top + rect.height > window.innerHeight - pad) top = e.clientY - rect.height - pad;
        staticTooltip.style.left = left + 'px';
        staticTooltip.style.top = top + 'px';
    }
});
document.addEventListener('mouseout', function(e) {
    if (e.target.closest('.transcript-token')) {
        staticTooltip.style.display = 'none';
    }
});
</script>

<?php echo $OUTPUT->footer(); ?>
