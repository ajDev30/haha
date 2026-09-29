<?php
/**
 * progress_dashboard.php — Progress & Graduation Dashboard
 *
 * Compares each student's pre-assessment (Phil-IRI) result with their
 * post-test (Reading Assessment Activity) result to determine if they
 * have improved sufficiently to graduate from the course.
 *
 * Access: Teachers / Admins with mod/readingassessment:viewallresults
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir.'/gradelib.php');

$cmid = required_param('cmid', PARAM_INT);

$cm      = get_coursemodule_from_id('readingassessment', $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cmid);

require_login($course, true, $cm);
require_capability('mod/readingassessment:viewallresults', $context);

// -------------------------------------------------------------------------
// Graduation thresholds
// -------------------------------------------------------------------------
define('GRAD_ACCURACY_THRESHOLD',      90);  // Oral reading accuracy %
define('GRAD_COMPREHENSION_THRESHOLD', 75);  // Comprehension %

// -------------------------------------------------------------------------
// Handle CSV export
// -------------------------------------------------------------------------
$export = optional_param('export', '', PARAM_ALPHA);

// -------------------------------------------------------------------------
// -------------------------------------------------------------------------
$action = optional_param('action', '', PARAM_ALPHAEXT);
if ($action === 'save_post_grades' && data_submitted()) {
    require_sesskey();
    $att_id = required_param('att_id', PARAM_INT);
    $accuracy = optional_param('accuracy', 0.0, PARAM_FLOAT);
    $comp = optional_param('comprehension', 0.0, PARAM_FLOAT);
    
    $attempt = $DB->get_record('readingassessment_post_att', ['id' => $att_id]);
    if ($attempt) {
        $DB->set_field('readingassessment_post_att', 'accuracy', $accuracy, ['id' => $att_id]);
        $DB->set_field('readingassessment_post_att', 'comprehension', $comp, ['id' => $att_id]);
        redirect(new moodle_url('/mod/readingassessment/progress_dashboard.php', ['cmid' => $cmid]), 'Scores updated successfully.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// -------------------------------------------------------------------------
// Load all pre-assessment profiles, link to Moodle users, fetch post-test
// -------------------------------------------------------------------------

$pre_profiles = $DB->get_records('readingassessment_ext_prof', null, 'lastname ASC, firstname ASC');

$course_grade_item = grade_item::fetch_course_item($course->id);

$rows = [];
foreach ($pre_profiles as $prof) {

    // Link to Moodle user via email
    $moodle_user = null;
    if (!empty($prof->email)) {
        $moodle_user = $DB->get_record('user',
            ['email' => $prof->email, 'deleted' => 0],
            'id, username, firstname, lastname, email'
        );
    }

    // Latest post-test attempt in this course
    $post_att = null;
    $all_attempts = [];
    $post_att = null;
    $course_total_pct = null;
    
    if ($moodle_user) {
        $all_attempts = $DB->get_records_sql("
            SELECT a.*, c.cmid AS attempt_cmid
            FROM {readingassessment_post_att} a
            JOIN {readingassessment_post_cfg} c ON c.id = a.cfgid
            JOIN {course_modules} cm ON cm.id = c.cmid
            WHERE a.userid = :uid AND cm.course = :courseid
            ORDER BY a.timecompleted DESC
        ", ['uid' => $moodle_user->id, 'courseid' => $course->id]);
        
        $post_att = reset($all_attempts) ?: null;
        
        // Fetch Course Total Grade
        if ($course_grade_item) {
            $gg = grade_grade::fetch(array('itemid' => $course_grade_item->id, 'userid' => $moodle_user->id));
            if ($gg && $gg->finalgrade !== null && $course_grade_item->grademax > $course_grade_item->grademin) {
                $course_total_pct = round(($gg->finalgrade - $course_grade_item->grademin) / ($course_grade_item->grademax - $course_grade_item->grademin) * 100, 2);
            }
        }
    }

    // Latest pre-assessment attempt (for numeric scores)
    $pre_att = $DB->get_record_sql("
        SELECT *
        FROM {readingassessment_ext_att}
        WHERE profileid = :pid
        ORDER BY timecompleted DESC
        LIMIT 1
    ", ['pid' => $prof->id]);

    // Graduation status
    $status = 'pending';
    if (!$moodle_user) {
        $status = 'not_enrolled';
    } else {
        $acc  = $post_att ? (float)$post_att->accuracy : null;
        // Use course total if available, otherwise fallback to reading assessment comprehension
        $comp = $course_total_pct !== null ? (float)$course_total_pct : ($post_att ? (float)$post_att->comprehension : null);
        
        if ($comp === null && !$post_att) {
            $status = 'pending';
        } else {
            // If they have no accuracy (e.g. only took a quiz), we only check comprehension
            if ($acc === null) {
                $status = ($comp >= GRAD_COMPREHENSION_THRESHOLD) ? 'graduated' : 'needs_practice';
            } else {
                $status = ($acc >= GRAD_ACCURACY_THRESHOLD && $comp >= GRAD_COMPREHENSION_THRESHOLD)
                            ? 'graduated' : 'needs_practice';
            }
        }
    }

    $rows[] = [
        'prof'        => $prof,
        'moodle_user' => $moodle_user,
        'pre_att'     => $pre_att,
        'post_att'    => $post_att,
        'all_attempts'=> $all_attempts,
        'course_total'=> $course_total_pct,
        'status'      => $status,
    ];
}

// -------------------------------------------------------------------------
// Summary counts
// -------------------------------------------------------------------------
$summary = ['total' => count($rows), 'graduated' => 0, 'needs_practice' => 0, 'pending' => 0, 'not_enrolled' => 0];
foreach ($rows as $r) $summary[$r['status']]++;

// -------------------------------------------------------------------------
// CSV Export
// -------------------------------------------------------------------------
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="progress_report_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Last Name', 'First Name', 'LRN', 'Section', 'Grade Level',
        'Pre-Assessment Classification',
        'Pre Accuracy %', 'Pre Comprehension %',
        'Post Accuracy %', 'Post Comprehension %', 'Post WPM',
        'Accuracy Change', 'Comprehension Change',
        'Status'
    ]);

    $status_labels = [
        'graduated'     => 'Graduated',
        'needs_practice'=> 'Needs Practice',
        'pending'       => 'Pending (No Post-Test)',
        'not_enrolled'  => 'Not Enrolled',
    ];

    foreach ($rows as $r) {
        $pre_acc  = $r['pre_att']  ? round((float)$r['pre_att']->accuracy_score, 1)       : '';
        $pre_comp = $r['pre_att']  ? round((float)$r['pre_att']->comprehension_score, 1)  : '';
        $post_acc  = $r['post_att'] ? round((float)$r['post_att']->accuracy, 1)            : '';
        $post_comp = $r['course_total'] !== null ? $r['course_total'] : ($r['post_att'] ? round((float)$r['post_att']->comprehension, 1) : '');
        $post_wpm  = $r['post_att'] ? round((float)$r['post_att']->wpm, 0)                 : '';

        $acc_change  = ($pre_acc !== '' && $post_acc !== '') ? round((float)$post_acc - (float)$pre_acc, 1) : '';
        $comp_change = ($pre_comp !== '' && $post_comp !== '') ? round((float)$post_comp - (float)$pre_comp, 1) : '';

        fputcsv($out, [
            $r['prof']->lastname,
            $r['prof']->firstname,
            $r['prof']->lrn,
            $r['prof']->section,
            $r['prof']->grade_level,
            $r['prof']->classification,
            $pre_acc, $pre_comp,
            $post_acc, $post_comp, $post_wpm,
            $acc_change !== '' ? ($acc_change >= 0 ? "+$acc_change" : $acc_change) : '',
            $comp_change !== '' ? ($comp_change >= 0 ? "+$comp_change" : $comp_change) : '',
            $status_labels[$r['status']] ?? $r['status'],
        ]);
    }
    fclose($out);
    exit;
}

// -------------------------------------------------------------------------
// Page output
// -------------------------------------------------------------------------
$PAGE->set_url(new moodle_url('/mod/readingassessment/progress_dashboard.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_title('Progress & Graduation Report');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
?>


<style>
:root{--ink:#172033;--muted:#667085;--line:#e6e9ef;--bg:#f6f8fb;--paper:#fff;--blue:#2563eb;--red:#dc2626;--yellow:#d8b51e;--purple:#7c3aed;--rose:#e11d48;--green:#16a34a;--subbg:#eef5ff}

.transcript-token{position:relative;display:inline-block;margin:0 1px;padding:0 2px;border-radius:4px;cursor:help;outline:none}.transcript-token:hover,.transcript-token:focus{background:#f3f6fb}.transcript-token.mis{border-bottom:2px solid #111}.transcript-token.sub{color:var(--blue);text-decoration:line-through;text-decoration-thickness:2px}.transcript-token.rev{color:var(--rose)}.transcript-token.rep{text-decoration:underline;text-decoration-style:wavy;text-decoration-thickness:2px;text-decoration-color:var(--yellow)}.transcript-token.trans{border-bottom:2px dashed var(--purple)}.transcript-token.self-repair{color:#9a6700;text-decoration:line-through;text-decoration-color:#d8a600;text-decoration-thickness:2px;background:#fff9db}.transcript-token.self-marker{color:#a16207;font-style:italic;background:#fff7db}.transcript-token.self-corrected{color:#15803d;border-bottom:2px solid #86efac;background:#f0fdf4}.transcript-token.uncertain{border-bottom:2px dotted #98a2b3}.transcript-token.azure-mis{box-shadow:inset 0 -2px 0 #111}
.transcript-token.mis::after,.transcript-token.sub::after,.transcript-token.rev::after{content:attr(data-expected);position:absolute;left:50%;transform:translateX(-50%);bottom:100%;white-space:nowrap;padding-bottom:2px;font:700 10px/1 ui-sans-serif,system-ui;color:inherit}
.transcript-token.mis::after{color:#111}
.transcript-token.sub::after{color:var(--blue);text-decoration:none}
.transcript-token.rev::after{color:var(--rose)}
.transcript-token.omit-placeholder{border:2px solid var(--red);border-radius:50%;padding:0 5px;color:#991b1b;background:#fff;cursor:help}
.transcript-token.omit-placeholder::before{content:"omitted";position:absolute;left:50%;transform:translateX(-50%);bottom:100%;white-space:nowrap;color:#b42318;font:700 9px/1 ui-sans-serif,system-ui;padding-bottom:2px}
.transcript-token.ins::before{content:"^";position:absolute;left:50%;transform:translateX(-50%);top:-.95em;color:#111;font:900 15px ui-sans-serif}
.transcript-token.ins{border-bottom:2px solid transparent}
.transcript-token.rep::before{content:"↶";position:absolute;left:50%;transform:translateX(-50%);top:-1.35em;color:#ae8c00;font:900 15px/1 ui-sans-serif}
.transcript-token.trans::before{content:"⇌";position:absolute;left:50%;transform:translateX(-50%);bottom:100%;color:var(--purple);font:900 14px/1 ui-sans-serif}
.transcript-token.self-repair{color:#9a6700;text-decoration:line-through;text-decoration-color:#d8a600;text-decoration-thickness:2px;background:#fff9db}
.transcript-token.self-repair::before{content:"repair";position:absolute;left:50%;transform:translateX(-50%);bottom:100%;color:#b26b00;font:700 9px/1 ui-sans-serif;white-space:nowrap;padding-bottom:2px}
.transcript-token.self-corrected{color:#15803d;border-bottom:2px solid #86efac;background:#f0fdf4}
.transcript-token.self-corrected::before{content:"S";position:absolute;left:50%;transform:translateX(-50%);bottom:100%;color:#15803d;font:900 10px/1 ui-sans-serif}
.transcript-token.self-corrected::after{content:attr(data-selfattempt);position:absolute;left:50%;transform:translateX(-50%);top:-1.9em;color:#b26b00;font:700 10px ui-sans-serif;white-space:nowrap}
.legend{display:flex;flex-wrap:wrap;gap:7px 14px;padding:9px 14px;border-top:1px solid var(--line);border-bottom:1px solid var(--line);font-size:11px;color:#667085}.legend-item{display:inline-flex;align-items:center;gap:6px}.legend-item i{display:inline-block;width:16px;height:10px}.legend-mis{border-bottom:3px solid #111}.legend-om{border:2px solid var(--red);border-radius:50%}.legend-sub{background:var(--subbg);border-bottom:2px solid var(--blue)}.legend-rep{border-bottom:3px wavy var(--yellow)}.legend-trans{border-bottom:2px dashed var(--purple)}.legend-rev{background:#ffe4e8}.legend-self{border-bottom:2px solid #f59e0b;background:#fff7db;border-radius:3px}.tip-self{margin-top:8px;border-top:1px solid rgba(255,255,255,.12);padding-top:7px}
.bottom-row{padding:11px 14px}.performance-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.performance-card{background:#fafbfc;border:1px solid var(--line);border-radius:10px;padding:10px 11px}.performance-card span{display:block;font-size:10px;letter-spacing:.08em;font-weight:900;color:var(--muted)}.performance-card strong{font-size:22px;line-height:1.1;display:block;margin-top:3px}.performance-card small{display:block;font-size:10px;color:#98a2b3;margin-top:3px}.miscue-strip{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.miscue-chip{border:1px solid var(--line);border-radius:999px;padding:6px 8px;font-size:10px;font-weight:800;background:#fff}.miscue-chip b{font-size:12px;margin-left:3px}.miscue-chip.mis{border-color:#c8c8c8}.miscue-chip.om{border-color:#fecaca;color:#991b1b}.miscue-chip.sub{border-color:#bfdbfe;color:#1d4ed8}.miscue-chip.rep{border-color:#f3e3a2;color:#7a6200}.miscue-chip.trans{border-color:#ddd6fe;color:#6d28d9}.miscue-chip.rev{border-color:#fecdd3;color:#be123c}.miscue-chip.self{border-color:#fde68a;color:#9a6700}.raw{margin:0 14px 14px}.raw summary{font-size:11px;color:#667085;cursor:pointer}.raw pre{max-height:360px;overflow:auto;margin-top:8px;padding:12px;background:#0b1020;color:#d9e1ff;border-radius:9px;font-size:10px}.word-tooltip{position:fixed;z-index:50;width:min(360px,calc(100vw - 24px));background:#111827;color:#f9fafb;border-radius:10px;box-shadow:0 16px 44px rgba(15,23,42,.28);padding:11px 12px;font-size:11px;line-height:1.45;pointer-events:none}.word-tooltip h4{margin:0 0 7px;font-size:12px}.tip-grid{display:grid;grid-template-columns:74px 1fr;gap:3px 8px}.tip-label{color:#9ca3af}.tip-value{color:#f9fafb;font-weight:700;overflow-wrap:anywhere}.tip-time{margin-top:8px;padding-top:8px;border-top:1px solid var(--line)}.tip-time code{font:700 12px/1.2 ui-monospace,SFMono-Regular,Menlo,monospace;color:#344054}.tip-phonemes{margin-top:8px;border-top:1px solid rgba(255,255,255,.12);padding-top:7px}.tip-phoneme-row{display:flex;justify-content:space-between;gap:10px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.tip-phoneme-row span:last-child{color:#cbd5e1}.tip-note{margin-top:7px;color:#cbd5e1;font-size:10px}
.mark{position:relative;display:inline-block;margin:0 2px}.mark.mis{border-bottom:3px solid #111}.mark.mis::after,.mark.sub::after,.mark.rev::after{position:absolute;left:50%;transform:translateX(-50%);bottom:100%;white-space:nowrap;padding-bottom:1px;font:700 10px/1.2 ui-sans-serif,system-ui}.mark.mis::after{content:attr(data-spoken);color:#111}.mark.om{border:2px solid var(--red);border-radius:50%;padding:0 4px;color:#8d1b1b}.mark.ins{min-width:14px;border-bottom:2px solid transparent}.mark.ins::before{content:"^";position:absolute;left:50%;transform:translateX(-50%);top:-.95em;color:#111;font:900 15px ui-sans-serif}.mark.ins::after{content:attr(data-spoken);position:absolute;left:50%;transform:translateX(-50%);top:-1.9em;color:#111;font:700 10px ui-sans-serif;white-space:nowrap}.mark.sub{color:var(--blue);text-decoration:line-through;text-decoration-thickness:2px;text-decoration-color:var(--blue)}.mark.sub::after{content:attr(data-spoken);color:var(--blue)}.mark.rep{color:#6b5500;text-decoration-line:underline;text-decoration-style:wavy;text-decoration-color:var(--yellow);text-decoration-thickness:3px;position:relative}.mark.rep::before{content:"↶";position:absolute;left:50%;transform:translateX(-50%);top:-1.45em;color:#ae8c00;font:900 16px/1 ui-sans-serif}.mark.trans{border-bottom:2px dashed var(--purple);position:relative}.mark.trans::before{content:"⇌";position:absolute;left:50%;transform:translateX(-50%);bottom:100%;color:var(--purple);font:900 14px/1 ui-sans-serif}.mark.rev{color:var(--rose)}.mark.rev::after{content:attr(data-reversed);color:var(--rose)}.mark.self-corrected{border-bottom:2px solid #86efac;background:#f0fdf4;color:#166534}.mark.self-corrected::before{content:"S";position:absolute;left:50%;transform:translateX(-50%);bottom:100%;color:#15803d;font:900 10px/1 ui-sans-serif;white-space:nowrap}.mark.self-corrected::after{content:attr(data-selfattempt);position:absolute;left:50%;transform:translateX(-50%);top:-1.9em;color:#b26b00;font:700 10px ui-sans-serif;white-space:nowrap}.mark.uncertain-word{border-bottom:2px dotted #98a2b3}
.dash-stat-card { border-radius: 10px; padding: 1.2rem 1rem; text-align: center; color: #fff; }
.dash-stat-card h2 { font-size: 2.4rem; font-weight: 700; margin: 0; }
.dash-stat-card small { font-size: 0.8rem; opacity: 0.85; }
.bg-graduated    { background: #28a745; }
.bg-needs        { background: #ffc107; color: #333 !important; }
.bg-needs h2     { color: #333; }
.bg-pending      { background: #17a2b8; }
.bg-notenrolled  { background: #6c757d; }

.badge-graduated    { background: #28a745; color: #fff; }
.badge-needs        { background: #ffc107; color: #333; }
.badge-pending      { background: #17a2b8; color: #fff; }
.badge-notenrolled  { background: #6c757d; color: #fff; }

.progress-arrow { font-size: 1.2rem; font-weight: 700; }
.arrow-up   { color: #28a745; }
.arrow-same { color: #6c757d; }
.arrow-down { color: #dc3545; }

.score-improved { color: #28a745; font-weight: 600; }
.score-worse    { color: #dc3545; font-weight: 600; }
.score-same     { color: #6c757d; }

.dash-section-title { border-left: 4px solid #007bff; padding-left: 0.75rem; margin-bottom: 1rem; }
</style>

<div class="container-fluid mt-3">

  <!-- Header -->
  <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap">
    <div>
      <h3 class="mb-1">📊 Progress &amp; Graduation Report</h3>
      <p class="text-muted mb-0">
        Comparing Pre-Assessment (Phil-IRI) vs Post-Test results ·
        <strong>Graduation:</strong> Accuracy ≥ <?php echo GRAD_ACCURACY_THRESHOLD; ?>% AND Comprehension ≥ <?php echo GRAD_COMPREHENSION_THRESHOLD; ?>%
      </p>
    </div>
    <div class="btn-group mt-2">
      <a href="<?php echo (new moodle_url('/mod/readingassessment/progress_dashboard.php', ['cmid' => $cmid, 'export' => 'csv']))->out(); ?>"
         class="btn btn-outline-success">⬇ Export CSV</a>
      <button onclick="window.print()" class="btn btn-outline-secondary">🖨 Print</button>
      <a href="<?php echo (new moodle_url('/mod/readingassessment/post_manage.php', ['cmid' => $cmid]))->out(); ?>"
         class="btn btn-outline-secondary">← Dashboard</a>
    </div>
  </div>

  <!-- Summary Cards -->
  <div class="row mb-4">
    <div class="col-6 col-md-3 mb-2">
      <div class="dash-stat-card bg-primary">
        <h2><?php echo $summary['total']; ?></h2>
        <small>Total Students</small>
      </div>
    </div>
    <div class="col-6 col-md-3 mb-2">
      <div class="dash-stat-card bg-graduated">
        <h2>✅ <?php echo $summary['graduated']; ?></h2>
        <small>Graduated</small>
      </div>
    </div>
    <div class="col-6 col-md-3 mb-2">
      <div class="dash-stat-card bg-needs">
        <h2>⚠️ <?php echo $summary['needs_practice']; ?></h2>
        <small>Needs Practice</small>
      </div>
    </div>
    <div class="col-6 col-md-3 mb-2">
      <div class="dash-stat-card bg-pending">
        <h2>🔄 <?php echo $summary['pending'] + $summary['not_enrolled']; ?></h2>
        <small>Pending / Not Enrolled</small>
      </div>
    </div>
  </div>

  <!-- Main Comparison Table -->
  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong class="dash-section-title">Student Progress Comparison</strong>
      <small class="text-muted"><?php echo count($rows); ?> student(s)</small>
    </div>
    <div class="card-body p-0">
      <div class="p-3 bg-light border-bottom d-flex flex-wrap align-items-center" style="gap: 15px;">
        <input type="text" id="progSearch" class="form-control form-control-sm" style="max-width: 250px;" placeholder="Search student, LRN, section...">
        <select id="progClassFilter" class="form-control form-control-sm" style="max-width: 180px;">
            <option value="">All Classifications</option>
            <option value="Independent">Independent</option>
            <option value="Instructional">Instructional</option>
            <option value="Frustration">Frustration</option>
            <option value="Non-Reader">Non-Reader</option>
        </select>
        <select id="progStatusFilter" class="form-control form-control-sm" style="max-width: 180px;">
            <option value="">All Statuses</option>
            <option value="Graduated">Graduated</option>
            <option value="Needs Practice">Needs Practice</option>
            <option value="No Post-Test Yet">No Post-Test Yet</option>
            <option value="Not Enrolled">Not Enrolled</option>
        </select>
      </div>
      <?php if (empty($rows)): ?>
        <p class="p-4 text-center text-muted">No pre-assessment data found yet.</p>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover table-sm mb-0" id="progress-table">
          <style>th.sortable:hover { background-color: #e9ecef; } .sort-icon { font-size: 0.8em; margin-left: 4px; color: #6c757d; }</style>
          <thead class="thead-light">
            <tr>
              <th class="sortable" data-sort="string" style="cursor: pointer;">Student <span class="sort-icon">↕</span></th>
              <th class="sortable" data-sort="string" style="cursor: pointer;">LRN / Section <span class="sort-icon">↕</span></th>
              <th class="text-center sortable" data-sort="string" style="cursor: pointer;">Pre-Assessment<br><small class="font-weight-normal">Classification</small> <span class="sort-icon">↕</span></th>
              <th class="text-center sortable" data-sort="float" style="cursor: pointer;">Pre-Test<br><small class="font-weight-normal">Acc% / Comp%</small> <span class="sort-icon">↕</span></th>
              <th class="text-center sortable" data-sort="float" style="cursor: pointer;">Post-Test<br><small class="font-weight-normal">Acc%</small> <span class="sort-icon">↕</span></th>
              <th class="text-center sortable" data-sort="float" style="cursor: pointer;">Course Grade<br><small class="font-weight-normal">Comp%</small> <span class="sort-icon">↕</span></th>
              <th class="text-center sortable" data-sort="float" style="cursor: pointer;">Post-Test<br><small class="font-weight-normal">WPM</small> <span class="sort-icon">↕</span></th>
              <th class="text-center sortable" data-sort="float" style="cursor: pointer;">Progress <span class="sort-icon">↕</span></th>
              <th class="text-center sortable" data-sort="string" style="cursor: pointer;">Status <span class="sort-icon">↕</span></th>
            </tr>
          </thead>
          <tbody>
          <?php 
            // Arrow logic (>3% change is meaningful)
            if (!function_exists('progressArrow')) {
                function progressArrow($delta) {
                    if ($delta === null) return '<span class="text-muted">—</span>';
                    if ($delta > 3)  return '<span class="progress-arrow arrow-up"  title="Improved">↑ +' . $delta . '%</span>';
                    if ($delta < -3) return '<span class="progress-arrow arrow-down" title="Declined">↓ ' . $delta . '%</span>';
                    return '<span class="progress-arrow arrow-same" title="No significant change">→ ' . ($delta >= 0 ? '+' : '') . $delta . '%</span>';
                }
            }
            
            foreach ($rows as $r):
            $prof     = $r['prof'];
            $pre_att  = $r['pre_att'];
            $post_att = $r['post_att'];
            $status   = $r['status'];

            // Pre scores
            $pre_acc  = $pre_att  ? round((float)$pre_att->accuracy_score, 1)      : null;
            $pre_comp = $pre_att  ? round((float)$pre_att->comprehension_score, 1) : null;

            // Post scores
            $post_acc  = $post_att ? round((float)$post_att->accuracy, 1)      : null;
            $post_comp = $r['course_total'] !== null ? $r['course_total'] : ($post_att ? round((float)$post_att->comprehension, 1) : null);
            $post_wpm  = $post_att ? round((float)$post_att->wpm, 0)           : null;

            // Delta calculations
            $acc_delta  = ($pre_acc !== null  && $post_acc !== null)  ? round($post_acc - $pre_acc, 1)   : null;
            $comp_delta = ($pre_comp !== null && $post_comp !== null) ? round($post_comp - $pre_comp, 1) : null;


            // Classification badge colour
            $class_colours = [
                'Independent'  => 'success',
                'Instructional'=> 'warning',
                'Frustration'  => 'danger',
                'Non-Reader'   => 'dark',
                'Nonreader'    => 'dark',
            ];
            $class_colour = $class_colours[$prof->classification] ?? 'secondary';

            // Status badge
            $status_map = [
                'graduated'      => ['label' => '✅ Graduated',        'cls' => 'badge-graduated'],
                'needs_practice' => ['label' => '⚠️ Needs Practice',   'cls' => 'badge-needs'],
                'pending'        => ['label' => '🔄 No Post-Test Yet',  'cls' => 'badge-pending'],
                'not_enrolled'   => ['label' => '📋 Not Enrolled',      'cls' => 'badge-notenrolled'],
            ];
            $status_info = $status_map[$status] ?? ['label' => $status, 'cls' => 'badge-secondary'];
          ?>
          <tr>
            <td>
              <strong><?php echo s($prof->lastname . ', ' . $prof->firstname); ?></strong>
              <?php if ($prof->middleinitial): ?><small class="text-muted"> <?php echo s($prof->middleinitial); ?>.</small><?php endif; ?>
              <?php if ($r['moodle_user']): ?>
                <br><small class="text-muted">@<?php echo s($r['moodle_user']->username); ?></small>
              <?php else: ?>
                <br><small class="text-muted font-italic">No Moodle account</small>
              <?php endif; ?>
            </td>
            <td>
              <small>
                <?php echo s($prof->lrn ?: '—'); ?><br>
                Gr.<?php echo (int)$prof->grade_level; ?> / <?php echo s($prof->section ?: '—'); ?>
              </small>
            </td>
            <td class="text-center">
              <?php if ($prof->classification): ?>
                <span class="badge badge-<?php echo $class_colour; ?> p-2">
                  <?php echo s($prof->classification); ?>
                </span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($pre_acc !== null): ?>
                <span class="font-weight-bold"><?php echo $pre_acc; ?>%</span>
                <br><small class="text-muted"><?php echo $pre_comp; ?>% comp</small>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($post_acc !== null): ?>
                <?php
                  $acc_cls = $post_acc >= GRAD_ACCURACY_THRESHOLD ? 'score-improved' : 'score-worse';
                ?>
                <span class="<?php echo $acc_cls; ?>"><?php echo $post_acc; ?>%</span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($post_comp !== null): ?>
                <?php
                  $comp_cls = $post_comp >= GRAD_COMPREHENSION_THRESHOLD ? 'score-improved' : 'score-worse';
                ?>
                <span class="<?php echo $comp_cls; ?>"><?php echo $post_comp; ?>%</span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php echo $post_wpm !== null ? $post_wpm : '<span class="text-muted">—</span>'; ?>
            </td>
            <td class="text-center">
              <div><?php echo progressArrow($acc_delta); ?> acc</div>
              <div><?php echo progressArrow($comp_delta); ?> comp</div>
            </td>
            <td class="text-center">
              <span class="badge p-2 <?php echo $status_info['cls']; ?>" style="font-size:12px;">
                <?php echo $status_info['label']; ?>
              </span>
              <?php if (!empty($r['all_attempts'])): 
                $avg_acc = 0; $avg_wpm = 0; $avg_comp = 0;
                if (count($r['all_attempts']) > 0) {
                    $sum_acc = 0; $sum_wpm = 0; $sum_comp = 0;
                    foreach ($r['all_attempts'] as $att) {
                        $sum_acc += (float)$att->accuracy;
                        $sum_wpm += (float)$att->wpm;
                        $sum_comp += (float)$att->comprehension;
                    }
                    $avg_acc = round($sum_acc / count($r['all_attempts']), 1);
                    $avg_wpm = round($sum_wpm / count($r['all_attempts']), 0);
                    $avg_comp = round($sum_comp / count($r['all_attempts']), 1);
                }

                $student_history = [
                    'userid' => $r['moodle_user'] ? $r['moodle_user']->id : 0,
                    'pre_acc' => $pre_acc,
                    'pre_comp' => $pre_comp,
                    'student_name' => $prof->lastname . ', ' . $prof->firstname,
                    'attempts' => [],
                    'averages' => [
                        'accuracy' => $avg_acc,
                        'wpm' => $avg_wpm,
                        'comprehension' => $avg_comp
                    ],
                    'course_total' => $r['course_total']
                ];

                foreach ($r['all_attempts'] as $att) {
                    $audio_url = '';
                    if (!empty($att->audio_file_id)) {
                        $attempt_context = context_module::instance($att->attempt_cmid, IGNORE_MISSING);
                        if ($attempt_context) {
                            $audio_url = moodle_url::make_pluginfile_url($attempt_context->id, 'mod_readingassessment', 'post_audio', $att->audio_file_id, '/', 'attempt_' . $att->id . '_' . $att->userid . '.webm')->out(false);
                        }
                    }
                    $student_history['attempts'][] = [
                        'id' => $att->id,
                        'accuracy' => round((float)$att->accuracy, 1),
                        'comprehension' => round((float)$att->comprehension, 1),
                        'wpm' => round((float)$att->wpm, 0),
                        'markup' => $att->transcript_markup,
                        'audio_url' => $audio_url,
                        'timecompleted' => userdate($att->timecompleted, '%d %b %Y, %H:%M')
                    ];
                }
              ?>
                <br><small class="text-muted"><?php echo userdate($post_att->timecompleted, '%d %b %Y'); ?></small>
                <div class="mt-1">
                    <button class="btn btn-sm btn-outline-primary" onclick='viewPostAttempt(this)' data-history='<?php echo s(json_encode($student_history, JSON_INVALID_UTF8_SUBSTITUTE)); ?>'>View Details</button>
                </div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Criteria Legend -->
  <div class="card mt-3 shadow-sm">
    <div class="card-header"><strong>📋 Graduation Criteria Reference</strong></div>
    <div class="card-body">
      <div class="row">
        <div class="col-md-6">
          <h6>Pre-Assessment (Phil-IRI)</h6>
          <table class="table table-sm table-bordered mb-0">
            <thead class="thead-light"><tr><th>Classification</th><th>Oral Reading</th><th>Comprehension</th></tr></thead>
            <tbody>
              <tr><td><span class="badge badge-success">Independent</span></td><td>97–100%</td><td>80–100%</td></tr>
              <tr><td><span class="badge badge-warning">Instructional</span></td><td>90–96%</td><td>59–79%</td></tr>
              <tr><td><span class="badge badge-danger">Frustration</span></td><td>≤ 89%</td><td>≤ 58%</td></tr>
            </tbody>
          </table>
        </div>
        <div class="col-md-6">
          <h6>Post-Test Graduation Threshold</h6>
          <table class="table table-sm table-bordered mb-0">
            <thead class="thead-light"><tr><th>Metric</th><th>Required Score</th></tr></thead>
            <tbody>
              <tr><td>Oral Reading Accuracy</td><td class="font-weight-bold text-success">≥ <?php echo GRAD_ACCURACY_THRESHOLD; ?>%</td></tr>
              <tr><td>Comprehension</td><td class="font-weight-bold text-success">≥ <?php echo GRAD_COMPREHENSION_THRESHOLD; ?>%</td></tr>
            </tbody>
          </table>
          <small class="text-muted mt-1 d-block">Both criteria must be met for graduation.</small>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- Detailed View (Hidden by default) -->
<div class="container-fluid mt-3 d-none" id="dashboard-details-view">
    <div class="card shadow-sm border-0 mb-5">
        <div class="card-header bg-white border-bottom p-3 d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <button class="btn btn-outline-secondary mr-3" onclick="closeDetailsView()">⬅️ Back to Submissions</button>
                <h4 class="mb-0 text-primary" id="details-student-name">Student Name</h4>
            </div>
            <div>
                <button class="btn btn-outline-primary" id="aral-toggle-btn" onclick="toggleAralView()" style="display:none;">Go to ARAL Progress Monitor</button>
            </div>
        </div>
        <div class="card-body p-4" id="details-content-area" style="background: #f8f9fa;">
            <!-- JS will render here -->
        </div>
    </div>
</div>

<script>
let currentStudentHistory = null;
const COURSE_ID = <?php echo $course->id; ?>;
const SESSKEY = '<?php echo sesskey(); ?>';
let isAralView = false;
let aralData = { settings: {}, activities: [] };

function viewPostAttempt(btn) {
    const raw = btn.getAttribute('data-history');
    if (!raw) return;
    currentStudentHistory = JSON.parse(raw);
    
    document.getElementById('details-student-name').innerText = currentStudentHistory.student_name + ' - Reading Test History';
    
    // Hide main, show details
    document.querySelector('.container-fluid.mt-3').classList.add('d-none');
    document.getElementById('dashboard-details-view').classList.remove('d-none');
    window.scrollTo(0, 0);
    
    document.getElementById('aral-toggle-btn').style.display = 'inline-block';
    isAralView = false;
    document.getElementById('aral-toggle-btn').innerText = "Go to ARAL Progress Monitor";
    document.getElementById('aral-toggle-btn').classList.remove('btn-outline-secondary');
    document.getElementById('aral-toggle-btn').classList.add('btn-outline-primary');
    
    renderHistoryTable();
}

function toggleAralView() {
    isAralView = !isAralView;
    const btn = document.getElementById('aral-toggle-btn');
    if (isAralView) {
        btn.innerText = "Back to Official Moodle Results";
        btn.classList.remove('btn-outline-primary');
        btn.classList.add('btn-outline-secondary');
        renderAralView();
    } else {
        btn.innerText = "Go to ARAL Progress Monitor";
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-outline-primary');
        renderHistoryTable();
    }
}

function renderAralView() {
    document.getElementById('details-content-area').innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary"></div><p class="mt-2">Loading ARAL Data...</p></div>';
    
    const formData = new FormData();
    formData.append('sesskey', SESSKEY);
    formData.append('action', 'get');
    formData.append('courseid', COURSE_ID);
    formData.append('userid', currentStudentHistory.userid);
    
    fetch('aral_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            buildAralHtml(data.settings, data.activities);
        } else {
            buildAralHtml({calc_method: 'equal_weight', prog_calc_method: 'simple_diff'}, []);
        }
    })
    .catch(e => {
        console.error(e);
        buildAralHtml({calc_method: 'equal_weight', prog_calc_method: 'simple_diff'}, []);
    });
}

function buildAralHtml(settings, activities) {
    if (!settings) settings = {calc_method: 'equal_weight', prog_calc_method: 'simple_diff'};
    if (!activities) activities = [];
    
    aralData.settings = settings;
    aralData.activities = activities;
    
    let postTestScore = computePostTestScore();
    let preTestScore = parseFloat(currentStudentHistory.pre_acc) || 0;
    if (settings.pre_score !== undefined && settings.pre_score !== '') {
        preTestScore = parseFloat(settings.pre_score) || 0;
    }
    
    let gain = computeGain(preTestScore, postTestScore, settings.prog_calc_method);
    
    let preLevel = settings.pre_reading_level || 'Frustration';
    let postLevel = 'Frustration';
    if (postTestScore >= 97) postLevel = 'Independent';
    else if (postTestScore >= 90) postLevel = 'Instructional';
    
    let levels = {'Frustration': 1, 'Instructional': 2, 'Independent': 3};
    let preN = levels[preLevel] || 1;
    let postN = levels[postLevel] || 1;
    
    let movement = 'Maintained';
    if (postN > preN) movement = 'Improved';
    if (postN < preN) movement = 'Regressed';
    
    let aralStatus = 'Continuing ARAL – Below Grade Level';
    if (postLevel === 'Independent') {
        aralStatus = 'Grade-Ready / Independent (Exit ARAL)';
    } else if (movement === 'Improved') {
        aralStatus = 'Improved but Below Grade Level';
    }

    let html = `
        <div id="aral-monitor-container">
            <h4 class="mb-4">ARAL Progress Monitor</h4>
            
            <div class="row">
                <div class="col-md-5 mb-3">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="card-header bg-light"><strong>Learner Info</strong></div>
                        <div class="card-body">
                            <p><strong>Name:</strong> ${currentStudentHistory.student_name}</p>
                            <p><strong>Pre-Assessment Accuracy:</strong> ${currentStudentHistory.pre_acc !== null ? currentStudentHistory.pre_acc + '%' : 'N/A'}</p>
                            <p><strong>Pre-Assessment Comprehension:</strong> ${currentStudentHistory.pre_comp !== null ? currentStudentHistory.pre_comp + '%' : 'N/A'}</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-7 mb-3">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="card-header bg-light"><strong>Automatic Results</strong></div>
                        <div class="card-body text-center p-3">
                            <div class="row">
                                <div class="col-3">
                                    <h6 class="text-muted mb-1" style="font-size:12px;">Composite Score</h6>
                                    <h4 class="text-primary mb-0">${postTestScore.toFixed(2)}%</h4>
                                </div>
                                <div class="col-3">
                                    <h6 class="text-muted mb-1" style="font-size:12px;">Improvement</h6>
                                    <h4 class="text-success mb-0">${gain > 0 ? '+' : ''}${gain.toFixed(2)}%</h4>
                                </div>
                                <div class="col-3">
                                    <h6 class="text-muted mb-1" style="font-size:12px;">Movement</h6>
                                    <h5 class="${movement==='Improved'?'text-success':movement==='Regressed'?'text-danger':'text-secondary'} mb-0">${movement}</h5>
                                </div>
                                <div class="col-3">
                                    <h6 class="text-muted mb-1" style="font-size:12px;">ARAL Status</h6>
                                    <h6 class="${postLevel==='Independent'?'text-success':'text-warning'} font-weight-bold mb-0">${aralStatus}</h6>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <strong>Assessment Settings</strong>
                    <button class="btn btn-sm btn-primary" onclick="saveAralSettings()">Save Settings</button>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <label>Base Pre-Score</label>
                            <input type="number" step="0.1" class="form-control form-control-sm" id="aral-set-pre" value="${preTestScore}">
                        </div>
                        <div class="col-md-3">
                            <label>Pre-Reading Level</label>
                            <select class="form-control form-control-sm" id="aral-set-level">
                                <option value="Frustration" ${preLevel === 'Frustration' ? 'selected' : ''}>Frustration (3 Levels Down)</option>
                                <option value="Instructional" ${preLevel === 'Instructional' ? 'selected' : ''}>Instructional (2 Levels Down)</option>
                                <option value="Independent" ${preLevel === 'Independent' ? 'selected' : ''}>Independent (Grade-Ready)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>Composite Calc Method</label>
                            <select class="form-control form-control-sm" id="aral-set-calc">
                                <option value="equal_weight" ${settings.calc_method === 'equal_weight' ? 'selected' : ''}>Equal Weight</option>
                                <option value="pooled_score" ${settings.calc_method === 'pooled_score' ? 'selected' : ''}>Pooled Score</option>
                                <option value="teacher_weight" ${settings.calc_method === 'teacher_weight' ? 'selected' : ''}>Teacher Weight</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>Progress Calc Method</label>
                            <select class="form-control form-control-sm" id="aral-set-prog">
                                <option value="simple_diff" ${settings.prog_calc_method === 'simple_diff' ? 'selected' : ''}>Percentage-Point Gain</option>
                                <option value="relative_gain" ${settings.prog_calc_method === 'relative_gain' ? 'selected' : ''}>Relative Improvement</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card shadow-sm border-0">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <strong>Post-Test Activities</strong>
                    <button class="btn btn-sm btn-success" onclick="openAralModal()">+ Add Activity</button>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Activity Name</th>
                                <th>Words</th>
                                <th>Miscues</th>
                                <th>Accuracy (%)</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${renderAralActivitiesRows()}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- ARAL Activity Modal -->
        <div class="modal" id="aralActivityModal" tabindex="-1" style="background: rgba(0,0,0,0.5);">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="aralActivityModalTitle">Add Activity</h5>
                <button type="button" class="close" onclick="closeAralModal()">
                  <span>&times;</span>
                </button>
              </div>
              <div class="modal-body">
                <input type="hidden" id="aral-act-id" value="">
                <div class="form-group">
                    <label>Activity Name</label>
                    <input type="text" class="form-control" id="aral-act-name">
                </div>
                <div class="form-group">
                    <label>Total Words</label>
                    <input type="number" class="form-control" id="aral-act-words">
                </div>
                <div class="form-group">
                    <label>Total Miscues</label>
                    <input type="number" class="form-control" id="aral-act-miscues">
                </div>
                <p><strong>Calculated Accuracy:</strong> <span id="aral-act-acc">0%</span></p>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAralModal()">Close</button>
                <button type="button" class="btn btn-primary" onclick="saveAralActivity()">Save Activity</button>
              </div>
            </div>
          </div>
        </div>
    `;
    
    document.getElementById('details-content-area').innerHTML = html;
    document.getElementById('aral-act-words').addEventListener('input', updateAralActAcc);
    document.getElementById('aral-act-miscues').addEventListener('input', updateAralActAcc);
}

function updateAralActAcc() {
    let w = parseFloat(document.getElementById('aral-act-words').value) || 0;
    let m = parseFloat(document.getElementById('aral-act-miscues').value) || 0;
    let acc = 0;
    if (w > 0) {
        acc = ((w - m) / w) * 100;
        if (acc < 0) acc = 0;
    }
    document.getElementById('aral-act-acc').innerText = acc.toFixed(2) + '%';
}

function renderAralActivitiesRows() {
    if (aralData.activities.length === 0) {
        return '<tr><td colspan="5" class="text-center text-muted">No activities found.</td></tr>';
    }
    return aralData.activities.map((act, index) => {
        let w = parseFloat(act.words) || 0;
        let m = parseFloat(act.miscues) || 0;
        let acc = w > 0 ? ((w - m) / w) * 100 : 0;
        return `
            <tr>
                <td>${act.name}</td>
                <td>${w}</td>
                <td>${m}</td>
                <td>${acc.toFixed(2)}%</td>
                <td>
                    <button class="btn btn-sm btn-outline-primary mr-1" onclick="editAralActivity(${index})">Edit</button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteAralActivity(${index})">Delete</button>
                </td>
            </tr>
        `;
    }).join('');
}

function computePostTestScore() {
    if (aralData.activities.length === 0) return 0;
    
    let method = aralData.settings.calc_method || 'equal_weight';
    if (method === 'equal_weight') {
        let sum = 0;
        aralData.activities.forEach(act => {
            let w = parseFloat(act.words) || 0;
            let m = parseFloat(act.miscues) || 0;
            if (w > 0) sum += ((w - m) / w) * 100;
        });
        return sum / aralData.activities.length;
    } else if (method === 'pooled_score') {
        let totalW = 0;
        let totalM = 0;
        aralData.activities.forEach(act => {
            totalW += parseFloat(act.words) || 0;
            totalM += parseFloat(act.miscues) || 0;
        });
        if (totalW > 0) return ((totalW - totalM) / totalW) * 100;
        return 0;
    } else if (method === 'teacher_weight') {
        if (aralData.activities.length === 1) {
            let act = aralData.activities[0];
            let w = parseFloat(act.words) || 0;
            let m = parseFloat(act.miscues) || 0;
            return w > 0 ? ((w - m) / w) * 100 : 0;
        }
        let lastAct = aralData.activities[aralData.activities.length - 1];
        let lastW = parseFloat(lastAct.words) || 0;
        let lastM = parseFloat(lastAct.miscues) || 0;
        let lastAcc = lastW > 0 ? ((lastW - lastM) / lastW) * 100 : 0;
        
        let sumRest = 0;
        let countRest = 0;
        for (let i = 0; i < aralData.activities.length - 1; i++) {
            let act = aralData.activities[i];
            let w = parseFloat(act.words) || 0;
            let m = parseFloat(act.miscues) || 0;
            if (w > 0) {
                sumRest += ((w - m) / w) * 100;
                countRest++;
            }
        }
        let avgRest = countRest > 0 ? sumRest / countRest : 0;
        return (lastAcc * 0.6) + (avgRest * 0.4);
    }
    return 0;
}

function computeGain(preScore, postScore, method) {
    if (method === 'relative_gain') {
        if (preScore > 0) {
            return ((postScore - preScore) / preScore) * 100;
        }
        return 0;
    }
    return postScore - preScore;
}

function openAralModal() {
    document.getElementById('aralActivityModalTitle').innerText = 'Add Activity';
    document.getElementById('aral-act-id').value = '';
    document.getElementById('aral-act-name').value = '';
    document.getElementById('aral-act-words').value = '';
    document.getElementById('aral-act-miscues').value = '';
    document.getElementById('aral-act-acc').innerText = '0%';
    
    document.getElementById('aralActivityModal').style.display = 'block';
    document.getElementById('aralActivityModal').classList.add('show');
}

function closeAralModal() {
    document.getElementById('aralActivityModal').style.display = 'none';
    document.getElementById('aralActivityModal').classList.remove('show');
}

function editAralActivity(index) {
    let act = aralData.activities[index];
    document.getElementById('aralActivityModalTitle').innerText = 'Edit Activity';
    document.getElementById('aral-act-id').value = index;
    document.getElementById('aral-act-name').value = act.name;
    document.getElementById('aral-act-words').value = act.words;
    document.getElementById('aral-act-miscues').value = act.miscues;
    updateAralActAcc();
    
    document.getElementById('aralActivityModal').style.display = 'block';
    document.getElementById('aralActivityModal').classList.add('show');
}

function saveAralSettings() {
    const preScore = document.getElementById('aral-set-pre').value;
    const preLevel = document.getElementById('aral-set-level').value;
    const calcMethod = document.getElementById('aral-set-calc').value;
    const progCalcMethod = document.getElementById('aral-set-prog').value;
    
    const formData = new FormData();
    formData.append('sesskey', SESSKEY);
    formData.append('action', 'save_settings');
    formData.append('courseid', COURSE_ID);
    formData.append('userid', currentStudentHistory.userid);
    formData.append('pre_score', preScore);
    formData.append('pre_reading_level', preLevel);
    formData.append('calc_method', calcMethod);
    formData.append('prog_calc_method', progCalcMethod);
    
    fetch('aral_api.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        aralData.settings = {pre_score: preScore, pre_reading_level: preLevel, calc_method: calcMethod, prog_calc_method: progCalcMethod};
        buildAralHtml(aralData.settings, aralData.activities); 
    })
    .catch(e => {
        console.error(e);
        aralData.settings = {pre_score: preScore, pre_reading_level: preLevel, calc_method: calcMethod, prog_calc_method: progCalcMethod};
        buildAralHtml(aralData.settings, aralData.activities);
    });
}

function saveAralActivity() {
    const id = document.getElementById('aral-act-id').value;
    const name = document.getElementById('aral-act-name').value;
    const words = document.getElementById('aral-act-words').value;
    const miscues = document.getElementById('aral-act-miscues').value;
    
    if (!name || !words || !miscues) {
        alert("Please fill all fields.");
        return;
    }
    
    const formData = new FormData();
    formData.append('sesskey', SESSKEY);
    formData.append('courseid', COURSE_ID);
    formData.append('userid', currentStudentHistory.userid);
    formData.append('name', name);
    formData.append('words', words);
    formData.append('miscues', miscues);
    
    if (id !== '') {
        formData.append('action', 'edit_activity');
        formData.append('activity_index', id);
    } else {
        formData.append('action', 'add_activity');
    }
    
    fetch('aral_api.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        closeAralModal();
        if (id !== '') {
            aralData.activities[id] = {name: name, words: words, miscues: miscues};
        } else {
            aralData.activities.push({name: name, words: words, miscues: miscues});
        }
        buildAralHtml(aralData.settings, aralData.activities);
    })
    .catch(e => {
        console.error(e);
        closeAralModal();
        if (id !== '') {
            aralData.activities[id] = {name: name, words: words, miscues: miscues};
        } else {
            aralData.activities.push({name: name, words: words, miscues: miscues});
        }
        buildAralHtml(aralData.settings, aralData.activities);
    });
}

function deleteAralActivity(index) {
    if (!confirm("Delete this activity?")) return;
    
    const formData = new FormData();
    formData.append('sesskey', SESSKEY);
    formData.append('action', 'delete_activity');
    formData.append('courseid', COURSE_ID);
    formData.append('userid', currentStudentHistory.userid);
    formData.append('activity_index', index);
    
    fetch('aral_api.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        aralData.activities.splice(index, 1);
        buildAralHtml(aralData.settings, aralData.activities);
    })
    .catch(e => {
        console.error(e);
        aralData.activities.splice(index, 1);
        buildAralHtml(aralData.settings, aralData.activities);
    });
}


function renderHistoryTable() {
    let html = `
        <div class="table-responsive mb-4 shadow-sm border rounded">
            <table class="table table-hover mb-0 bg-white">
                <thead class="thead-light">
                    <tr>
                        <th>Submitted</th>
                        <th class="text-center">Word Accuracy</th>
                        <th class="text-center">Comprehension</th>
                        <th class="text-center">Reading Speed</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    currentStudentHistory.attempts.forEach((att, index) => {
        html += `
                    <tr>
                        <td class="align-middle">${att.timecompleted}</td>
                        <td class="text-center align-middle font-weight-bold" style="color: #6610f2;">${att.accuracy}%</td>
                        <td class="text-center align-middle font-weight-bold" style="color: #fd7e14;">${att.comprehension}%</td>
                        <td class="text-center align-middle font-weight-bold" style="color: #0d6efd;">${att.wpm} WPM</td>
                        <td class="text-center align-middle">
                            <button class="btn btn-sm btn-primary" onclick="reviewSpecificAttempt(${index})">Review markup and oral reading</button>
                        </td>
                    </tr>
        `;
    });
    
    html += `
                </tbody>
            </table>
        </div>
        
        <h5 class="mt-4 mb-3 text-secondary">Summary Aggregations</h5>
        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body bg-light rounded text-center p-4">
                        <h6 class="text-muted text-uppercase tracking-wide border-bottom pb-2 mb-3">Overall Of The Oral Reading Test</h6>
                        <div class="d-flex justify-content-around">
                            <div><small class="text-muted d-block">Avg Accuracy</small><strong style="font-size:1.5rem; color:#6610f2;">${currentStudentHistory.averages.accuracy}%</strong></div>
                            <div><small class="text-muted d-block">Avg Comprehension</small><strong style="font-size:1.5rem; color:#fd7e14;">${currentStudentHistory.averages.comprehension}%</strong></div>
                            <div><small class="text-muted d-block">Avg Speed</small><strong style="font-size:1.5rem; color:#0d6efd;">${currentStudentHistory.averages.wpm} <small>WPM</small></strong></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 mb-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body bg-light rounded text-center p-4">
                        <h6 class="text-muted text-uppercase tracking-wide border-bottom pb-2 mb-3">Other Activities (Course Total)</h6>
                        <div class="mt-2">
                            ${currentStudentHistory.course_total !== null 
                                ? `<span style="font-size: 2.5rem; font-weight: bold; color: #198754;">${currentStudentHistory.course_total}%</span><br><small class="text-muted">Aggregated Course Grade</small>`
                                : `<span class="text-muted font-italic">No course grade available</span>`
                            }
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('details-content-area').innerHTML = html;
}

function reviewSpecificAttempt(index) {
    const att = currentStudentHistory.attempts[index];
    
    let html = `
        <div class="mb-4">
            <button class="btn btn-outline-secondary btn-sm" onclick="renderHistoryTable()">⬅️ Back to Test History</button>
        </div>
        
        <div class="row justify-content-center mb-4">
            <div class="col-md-10">
                <div class="d-flex justify-content-around bg-white p-4 border rounded shadow-sm text-center">
                    <div><div class="text-muted small text-uppercase font-weight-bold tracking-wide">Submitted</div><div class="font-weight-bold" style="font-size: 1.2rem;">${att.timecompleted}</div></div>
                    <div><div class="text-muted small text-uppercase font-weight-bold tracking-wide">Reading Speed</div><div class="font-weight-bold" style="font-size: 1.5rem; color: #0d6efd;">${att.wpm} <span style="font-size: 1rem;">WPM</span></div></div>
                    <div><div class="text-muted small text-uppercase font-weight-bold tracking-wide">Word Accuracy</div><div class="font-weight-bold" style="font-size: 1.5rem; color: #6610f2;">${att.accuracy}%</div></div>
                    <div><div class="text-muted small text-uppercase font-weight-bold tracking-wide">Comprehension</div><div class="font-weight-bold" style="font-size: 1.5rem; color: #fd7e14;">${att.comprehension}%</div></div>
                </div>
            </div>
        </div>
    `;
    
    if (att.audio_url) {
        html += `
        <div class="row justify-content-center mb-4">
            <div class="col-md-10">
                <audio controls src="${att.audio_url}" class="w-100 shadow-sm" style="border-radius: 50px;"></audio>
            </div>
        </div>`;
    }
    
    html += `
        <div class="row justify-content-center mb-4">
            <div class="col-md-10">
                <div class="card border-0 shadow-sm rounded">
                    <div class="card-body bg-white rounded p-4">
                        <h5 class="mb-3 text-primary border-bottom pb-2">🗣️ Transcript Markup</h5>
                        <div style="font-size: 1.25rem; line-height: 2.2;">
                            ${att.markup || '<span class="text-muted">No markup available</span>'}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    html += `
        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="card border-0 shadow-sm rounded">
                    <div class="card-body bg-light rounded p-4">
                        <h5 class="border-bottom pb-2 mb-3">📝 Manual Grading Override</h5>
                        <form method="POST" action="progress_dashboard.php?cmid=<?php echo $cmid; ?>">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <input type="hidden" name="action" value="save_post_grades">
                            <input type="hidden" name="att_id" value="${att.id}">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label font-weight-bold">Oral Reading Accuracy (%)</label>
                                    <input type="number" step="0.1" max="100" class="form-control form-control-lg" name="accuracy" value="${att.accuracy}">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label font-weight-bold">Comprehension Score (%)</label>
                                    <input type="number" step="0.1" max="100" class="form-control form-control-lg" name="comprehension" value="${att.comprehension}">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-lg mt-2 w-100 font-weight-bold shadow-sm">💾 Save Override</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('details-content-area').innerHTML = html;
}

function closeDetailsView() {
    document.getElementById('dashboard-details-view').classList.add('d-none');
    document.querySelector('.container-fluid.mt-3').classList.remove('d-none');
    window.scrollTo(0, 0);
}

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

// --- Filter and Sort Logic ---
document.addEventListener('DOMContentLoaded', function() {
    const table = document.getElementById('progress-table');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const searchInput = document.getElementById('progSearch');
    const classFilter = document.getElementById('progClassFilter');
    const statusFilter = document.getElementById('progStatusFilter');
    const headers = table.querySelectorAll('th.sortable');
    
    function filterTable() {
        const query = searchInput.value.toLowerCase();
        const cls = classFilter.value.toLowerCase();
        const stat = statusFilter.value.toLowerCase();
        let visibleCount = 0;
        
        tbody.querySelectorAll('tr').forEach(row => {
            const text = row.innerText.toLowerCase();
            const rowCls = (row.querySelector('td:nth-child(3)')?.innerText || '').toLowerCase();
            const rowStat = (row.querySelector('td:nth-child(9)')?.innerText || '').toLowerCase();
            
            const matchSearch = text.includes(query);
            const matchCls = cls === '' || rowCls.includes(cls);
            const matchStat = stat === '' || rowStat.includes(stat);
            
            if (matchSearch && matchCls && matchStat) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Update header count if the element exists
        const countHeader = document.querySelector('.dash-section-title + small');
        if (countHeader) countHeader.innerText = visibleCount + ' student(s)';
    }
    
    searchInput.addEventListener('input', filterTable);
    classFilter.addEventListener('change', filterTable);
    statusFilter.addEventListener('change', filterTable);
    
    let currentSortCol = -1;
    let currentSortAsc = true;
    
    headers.forEach((th, index) => {
        th.addEventListener('click', () => {
            const type = th.getAttribute('data-sort');
            const isAsc = currentSortCol === index ? !currentSortAsc : true;
            currentSortCol = index;
            currentSortAsc = isAsc;
            
            // Reset icons
            headers.forEach(h => h.querySelector('.sort-icon').innerText = '↕');
            th.querySelector('.sort-icon').innerText = isAsc ? '⬆' : '⬇';
            
            const rowsArray = Array.from(tbody.querySelectorAll('tr'));
            rowsArray.sort((a, b) => {
                let valA = a.children[index].innerText.trim();
                let valB = b.children[index].innerText.trim();
                
                if (type === 'float') {
                    // Extract numbers, ignore +, -, %, up/down arrows
                    valA = parseFloat(valA.replace(/[^0-9.-]+/g, '')) || 0;
                    valB = parseFloat(valB.replace(/[^0-9.-]+/g, '')) || 0;
                    return isAsc ? valA - valB : valB - valA;
                } else {
                    return isAsc ? valA.localeCompare(valB) : valB.localeCompare(valA);
                }
            });
            
            rowsArray.forEach(row => tbody.appendChild(row));
        });
    });
});
</script>

<?php echo $OUTPUT->footer(); ?>
