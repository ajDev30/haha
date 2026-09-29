<?php
/**
 * post_manage.php — Teacher dashboard for the post-test activity.
 *
 * Shows:
 *  - Summary stats (total attempts, avg accuracy, avg WPM, avg comprehension)
 *  - Attempts table with per-student scores and links to detail view
 *  - Link to edit the activity (post_edit.php)
 *
 * This file is completely separate from external_manage.php (pre-assessment).
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$cmid = required_param('cmid', PARAM_INT);

$cm      = get_coursemodule_from_id('readingassessment', $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cmid);

require_login($course, true, $cm);
require_capability('mod/readingassessment:viewallresults', $context);

$PAGE->set_url(new moodle_url('/mod/readingassessment/post_manage.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_title('Assessment Dashboard');
$PAGE->set_heading($course->fullname);

// -------------------------------------------------------------------------
// Load config and attempts
// -------------------------------------------------------------------------
$cfg = $DB->get_record('readingassessment_post_cfg', ['cmid' => $cmid]);

$attempts = [];
$summary  = ['total' => 0, 'avg_acc' => 0, 'avg_wpm' => 0, 'avg_comp' => 0];

if ($cfg) {
    $attempts = $DB->get_records_sql("
        SELECT a.*, u.firstname, u.lastname, u.email
        FROM {readingassessment_post_att} a
        JOIN {user} u ON u.id = a.userid
        WHERE a.cfgid = :cfgid
        ORDER BY a.timecompleted DESC
    ", ['cfgid' => $cfg->id]);

    if (count($attempts) > 0) {
        $summary['total']    = count($attempts);
        $summary['avg_acc']  = round(array_sum(array_column($attempts, 'accuracy'))  / $summary['total'], 1);
        $summary['avg_wpm']  = round(array_sum(array_column($attempts, 'wpm'))        / $summary['total'], 1);
        $summary['avg_comp'] = round(array_sum(array_column($attempts, 'comprehension')) / $summary['total'], 1);
    }
}

echo $OUTPUT->header();
?>

<div class="container-fluid mt-3">

  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h3 class="mb-0">📊 Assessment Dashboard</h3>
      <p class="text-muted mb-0"><?php echo s($cfg->passage_title ?? 'Not configured'); ?></p>
    </div>
    <div class="btn-group">
      <?php if (has_capability('mod/readingassessment:editcontent', $context)): ?>
      <a href="<?php echo (new moodle_url('/mod/readingassessment/post_edit.php', ['cmid' => $cmid]))->out(); ?>" class="btn btn-primary">✏️ Edit Assessment</a>
      <?php endif; ?>
      <a href="<?php echo (new moodle_url('/mod/readingassessment/progress_dashboard.php', ['cmid' => $cmid]))->out(); ?>" class="btn btn-success">📊 Progress &amp; Graduation</a>
      <a href="<?php echo (new moodle_url('/course/view.php', ['id' => $course->id]))->out(); ?>" class="btn btn-outline-secondary">← Course</a>
    </div>
  </div>

  <?php if (!$cfg): ?>
  <div class="alert alert-warning">
    This activity has not been configured yet. <a href="<?php echo (new moodle_url('/mod/readingassessment/post_edit.php', ['cmid' => $cmid]))->out(); ?>">Set it up now →</a>
  </div>
  <?php else: ?>

  <!-- Summary Cards -->
  <div class="row mb-4">
    <div class="col-md-3">
      <div class="card text-center p-3 border-primary">
        <h2 class="text-primary"><?php echo $summary['total']; ?></h2>
        <small class="text-muted">Total Attempts</small>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card text-center p-3 border-success">
        <h2 class="text-success"><?php echo $summary['avg_acc']; ?>%</h2>
        <small class="text-muted">Avg. Accuracy</small>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card text-center p-3 border-info">
        <h2 class="text-info"><?php echo $summary['avg_wpm']; ?></h2>
        <small class="text-muted">Avg. WPM</small>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card text-center p-3 border-warning">
        <h2 class="text-warning"><?php echo $summary['avg_comp']; ?>%</h2>
        <small class="text-muted">Avg. Comprehension</small>
      </div>
    </div>
  </div>

  <!-- Passage preview -->
  <div class="card mb-4">
    <div class="card-header"><strong>📖 Passage: <?php echo s($cfg->passage_title); ?></strong></div>
    <div class="card-body">
      <p><?php echo s(mb_substr($cfg->passage_text ?? '', 0, 250)); ?>…</p>
      <?php
        $q_count = count(@json_decode($cfg->questions_json ?? '[]', true) ?: []);
        echo '<small class="text-muted">' . $q_count . ' comprehension question(s) · Mastery threshold: ' . (int)$cfg->mastery_threshold . '%</small>';
      ?>
    </div>
  </div>

  <!-- Attempts Table -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>📋 Student Attempts</strong>
      <small class="text-muted"><?php echo count($attempts); ?> submission(s)</small>
    </div>
    <div class="card-body p-0">
      <?php if (empty($attempts)): ?>
        <p class="p-4 text-muted text-center">No student submissions yet.</p>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="thead-light">
            <tr>
              <th>Student</th>
              <th>Submitted</th>
              <th>Accuracy</th>
              <th>WPM</th>
              <th>Comprehension</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($attempts as $att): ?>
            <tr>
              <td>
                <?php echo s($att->firstname . ' ' . $att->lastname); ?><br>
                <small class="text-muted"><?php echo s($att->email); ?></small>
              </td>
              <td><?php echo userdate($att->timecompleted, '%d %b %Y %H:%M'); ?></td>
              <td>
                <?php
                  $acc = round($att->accuracy, 1);
                  $badge = ($acc >= 95) ? 'success' : (($acc >= 80) ? 'warning' : 'danger');
                  echo "<span class=\"badge badge-{$badge} p-2\">{$acc}%</span>";
                ?>
              </td>
              <td><?php echo round($att->wpm, 0); ?></td>
              <td>
                <?php
                  $comp = round($att->comprehension, 1);
                  $badge_c = ($comp >= 75) ? 'success' : (($comp >= 50) ? 'warning' : 'danger');
                  echo "<span class=\"badge badge-{$badge_c} p-2\">{$comp}%</span>";
                ?>
              </td>
              <td>
                <a href="<?php echo (new moodle_url('/mod/readingassessment/post_results.php', ['attid' => $att->id, 'cmid' => $cmid]))->out(); ?>" class="btn btn-sm btn-outline-primary">View Detail</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php endif; ?>
</div>

<?php echo $OUTPUT->footer(); ?>
