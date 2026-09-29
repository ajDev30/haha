<?php
defined('MOODLE_INTERNAL') || die();

function readingassessment_add_instance($readingassessment) {
    global $DB;
    $readingassessment->timecreated = time();
    $id = $DB->insert_record('readingassessment', $readingassessment);
    return $id;
}

function readingassessment_update_instance($readingassessment) {
    global $DB;
    $readingassessment->timemodified = time();
    $readingassessment->id = $readingassessment->instance;
    return $DB->update_record('readingassessment', $readingassessment);
}

function readingassessment_delete_instance($id) {
    global $DB;
    if (!$readingassessment = $DB->get_record('readingassessment', ['id' => $id])) {
        return false;
    }
    // Get the course module to find cmid for post-test cleanup
    $cm = get_coursemodule_from_instance('readingassessment', $id);
    if ($cm) {
        $cfg = $DB->get_record('readingassessment_post_cfg', ['cmid' => $cm->id]);
        if ($cfg) {
            // Clean up all post-test attempts and responses
            $att_ids = $DB->get_fieldset_select('readingassessment_post_att', 'id', 'cfgid = ?', [$cfg->id]);
            if ($att_ids) {
                list($in, $params) = $DB->get_in_or_equal($att_ids);
                $DB->delete_records_select('readingassessment_post_resp', "attid $in", $params);
                $DB->delete_records_select('readingassessment_post_coach', "attid $in", $params);
                $DB->delete_records('readingassessment_post_att', ['cfgid' => $cfg->id]);
            }
            $DB->delete_records('readingassessment_post_cfg', ['id' => $cfg->id]);
        }
    }
    $DB->delete_records('readingassessment', ['id' => $readingassessment->id]);
    return true;
}


// Add a direct link to the Moodle Primary Navigation (Top Navbar)
function readingassessment_extend_navigation_frontpage(navigation_node $navigation, $course = null) {
    global $USER;
    $syscontext = context_system::instance();
    
    // Everyone (including visitors/students) gets a link to take the test
    $take_url = new moodle_url('/mod/readingassessment/external.php');
    $navigation->add(
        'Take Phil-IRI',
        $take_url,
        navigation_node::TYPE_SETTING,
        null,
        'philiri_take',
        new pix_icon('i/course', '')
    );
    
    // Inject a prominent "Take Phil-IRI" floating button on the frontpage body
    global $PAGE;
    if ($course && $course->id == SITEID) {
        $btn_url = $take_url->out(false);
        $js = <<<JS
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('philiri-floating-btn')) return;
            var btn = document.createElement('a');
            btn.id = 'philiri-floating-btn';
            btn.href = '{$btn_url}';
            btn.innerHTML = '📖 Take Phil-IRI Assessment';
            btn.style.position = 'fixed';
            btn.style.bottom = '30px';
            btn.style.right = '30px';
            btn.style.zIndex = '9999';
            btn.style.backgroundColor = '#0d6efd';
            btn.style.color = '#fff';
            btn.style.padding = '15px 25px';
            btn.style.borderRadius = '50px';
            btn.style.boxShadow = '0 4px 10px rgba(0,0,0,0.3)';
            btn.style.fontSize = '18px';
            btn.style.fontWeight = 'bold';
            btn.style.textDecoration = 'none';
            btn.style.transition = 'all 0.3s ease';
            btn.onmouseover = function() { this.style.transform = 'scale(1.05)'; this.style.backgroundColor = '#0b5ed7'; };
            btn.onmouseout = function() { this.style.transform = 'scale(1)'; this.style.backgroundColor = '#0d6efd'; };
            document.body.appendChild(btn);
        });
JS;
        $PAGE->requires->js_init_code($js);
    }

    // Admins and Managers get the Teacher Dashboard link
    global $DB;
    $is_teacher = false;
    if (isloggedin() && !isguestuser()) {
        $is_teacher = $DB->record_exists_sql("
            SELECT 1 FROM {role_assignments} ra
            JOIN {role} r ON ra.roleid = r.id
            WHERE ra.userid = ? AND r.shortname IN ('editingteacher', 'teacher', 'manager')
        ", [$USER->id]);
    }
    
    // The user requested to hide the dashboard link from the frontpage header/navbar.
    // Teachers and admins will access it via another method.
    /*
    if ($is_teacher || is_siteadmin()) {
        $dash_url = new moodle_url('/mod/readingassessment/external_manage.php');
        $navigation->add(
            'Phil-IRI Dashboard',
            $dash_url,
            navigation_node::TYPE_SETTING,
            null,
            'philiri_dashboard',
            new pix_icon('i/settings', '')
        );
    }
    */
}

/**
 * Serves files for the readingassessment module.
 *
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param context $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - just send the file
 */
function readingassessment_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $DB, $USER;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, true, $cm);

    if ($filearea === 'post_audio') {
        $itemid = (int)array_shift($args); // attempt id
        
        $attempt = $DB->get_record('readingassessment_post_att', ['id' => $itemid], '*', IGNORE_MISSING);
        if (!$attempt) {
            return false;
        }

        // Security: must be teacher or the student who made the attempt
        if (!has_capability('mod/readingassessment:viewallresults', $context) && $attempt->userid != $USER->id) {
            return false;
        }

        $filename = array_shift($args);

        $fs = get_file_storage();
        $file = $fs->get_file($context->id, 'mod_readingassessment', $filearea, $itemid, '/', $filename);

        if (!$file || $file->is_directory()) {
            return false;
        }

        send_stored_file($file, 0, 0, $forcedownload, $options);
    }
    
    return false;
}
