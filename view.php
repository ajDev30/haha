<?php
/**
 * Standard Moodle activity view.php for mod_readingassessment.
 *
 * This file is the main entry point when a student or teacher clicks the activity
 * link in a course. It checks permissions and routes to the appropriate interface.
 *
 * - Students → post_view.php (reading assessment + coach)
 * - Teachers/Managers → post_manage.php (results overview)
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// --- Standard Moodle activity setup ---
$id = optional_param('id', 0, PARAM_INT);        // Course module ID
$n  = optional_param('n', 0, PARAM_INT);          // Activity instance ID

if ($id) {
    $cm         = get_coursemodule_from_id('readingassessment', $id, 0, false, MUST_EXIST);
    $course     = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $moduleinstance = $DB->get_record('readingassessment', ['id' => $cm->instance], '*', MUST_EXIST);
} else if ($n) {
    $moduleinstance = $DB->get_record('readingassessment', ['id' => $n], '*', MUST_EXIST);
    $course     = $DB->get_record('course', ['id' => $moduleinstance->course], '*', MUST_EXIST);
    $cm         = get_coursemodule_from_instance('readingassessment', $moduleinstance->id, $course->id, false, MUST_EXIST);
    $id = $cm->id;
} else {
    throw new moodle_exception('missingparameter');
}

require_login($course, true, $cm);

$context = context_module::instance($cm->id);

// Check if user is a teacher/manager → show manage view
$is_teacher = has_capability('mod/readingassessment:viewallresults', $context);

// Route appropriately
if ($is_teacher) {
    redirect(new moodle_url('/mod/readingassessment/post_manage.php', ['cmid' => $cm->id]));
} else {
    redirect(new moodle_url('/mod/readingassessment/post_view.php', ['cmid' => $cm->id]));
}
