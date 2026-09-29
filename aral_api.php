<?php
require_once('../../config.php');
require_once($CFG->libdir.'/adminlib.php');

$action = required_param('action', PARAM_ALPHANUMEXT);
$courseid = required_param('courseid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);

// Basic capability checks
require_login($courseid);
require_sesskey();
$context = context_course::instance($courseid);
require_capability('mod/readingassessment:viewallresults', $context); // Assuming viewallresults is the capability

$response = array('status' => 'error', 'message' => 'Unknown action');

try {
    switch ($action) {
        case 'get':
            $settings = $DB->get_record('readingassessment_aral_set', array('userid' => $userid, 'courseid' => $courseid));
            $activities = $DB->get_records('readingassessment_aral_act', array('userid' => $userid, 'courseid' => $courseid), 'activity_date ASC');
            
            $response = array(
                'status' => 'success',
                'settings' => $settings ? $settings : null,
                'activities' => $activities ? array_values($activities) : array()
            );
            break;

        case 'save_settings':
            $settings = new stdClass();
            $settings->userid = $userid;
            $settings->courseid = $courseid;
            $settings->pre_score = optional_param('pre_score', null, PARAM_FLOAT);
            $settings->pre_reading_level = optional_param('pre_reading_level', '', PARAM_TEXT);
            $settings->calc_method = optional_param('calc_method', '', PARAM_TEXT);
            $settings->prog_calc_method = optional_param('prog_calc_method', '', PARAM_TEXT);

            $existing = $DB->get_record('readingassessment_aral_set', array('userid' => $userid, 'courseid' => $courseid));
            if ($existing) {
                $settings->id = $existing->id;
                $DB->update_record('readingassessment_aral_set', $settings);
            } else {
                $settings->id = $DB->insert_record('readingassessment_aral_set', $settings);
            }

            $response = array('status' => 'success', 'data' => $settings);
            break;

        case 'save_activity':
            $activity = new stdClass();
            $activity->userid = $userid;
            $activity->courseid = $courseid;
            $activity->activity_name = required_param('activity_name', PARAM_TEXT);
            $activity->activity_date = required_param('activity_date', PARAM_INT);
            $activity->assessment_type = required_param('assessment_type', PARAM_TEXT);
            $activity->total_items = optional_param('total_items', null, PARAM_FLOAT);
            $activity->correct_answers = optional_param('correct_answers', null, PARAM_FLOAT);
            $activity->percentage_score = optional_param('percentage_score', null, PARAM_FLOAT);
            $activity->weight = optional_param('weight', null, PARAM_FLOAT);
            $activity->oral_total_words = optional_param('oral_total_words', null, PARAM_INT);
            $activity->oral_miscues = optional_param('oral_miscues', null, PARAM_INT);
            $activity->oral_time_seconds = optional_param('oral_time_seconds', null, PARAM_INT);
            $activity->notes = optional_param('notes', '', PARAM_TEXT);

            $id = optional_param('id', 0, PARAM_INT);
            if ($id) {
                $activity->id = $id;
                $DB->update_record('readingassessment_aral_act', $activity);
            } else {
                $activity->id = $DB->insert_record('readingassessment_aral_act', $activity);
            }

            $response = array('status' => 'success', 'data' => $activity);
            break;

        case 'delete_activity':
            $id = required_param('id', PARAM_INT);
            $DB->delete_records('readingassessment_aral_act', array('id' => $id, 'userid' => $userid, 'courseid' => $courseid));
            $response = array('status' => 'success');
            break;
    }
} catch (Exception $e) {
    $response = array('status' => 'error', 'message' => $e->getMessage());
}

echo json_encode($response);
die();
