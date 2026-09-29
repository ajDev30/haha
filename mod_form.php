<?php
/**
 * mod_form.php — Moodle activity settings form for mod_readingassessment.
 *
 * This is the standard Moodle form shown when a teacher adds or edits the
 * "Reading Assessment" activity in a course. It collects the activity name
 * and description. Advanced post-test configuration (passage, questions, TTS)
 * is handled separately via post_edit.php after the activity is created.
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_readingassessment_mod_form extends moodleform_mod {

    public function definition() {
        global $CFG;

        $mform = $this->_form;

        // ----------------------------------------------------------------
        // General settings section
        // ----------------------------------------------------------------
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Activity name
        $mform->addElement('text', 'name', get_string('activityname', 'readingassessment'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Standard intro / description
        $this->standard_intro_elements();

        // Attempts allowed
        $options = [0 => 'Unlimited'];
        for ($i = 1; $i <= 10; $i++) {
            $options[$i] = $i;
        }
        $mform->addElement('select', 'attempts_allowed', 'Attempts allowed', $options);
        $mform->setDefault('attempts_allowed', 0);
        $mform->setType('attempts_allowed', PARAM_INT);

        // ----------------------------------------------------------------
        // Info box — tells teacher about the next step
        // ----------------------------------------------------------------
        $mform->addElement('html', '<div class="alert alert-info mt-2">
            <strong>📝 Next step:</strong> After saving, you will be directed to the Assessment Editor
            where you can add the reading passage, comprehension questions, TTS voice settings, and mastery threshold.
        </div>');

        // ----------------------------------------------------------------
        // Standard course module elements (availability, grade, etc.)
        // ----------------------------------------------------------------
        $this->standard_coursemodule_elements();

        // ----------------------------------------------------------------
        // Action buttons
        // ----------------------------------------------------------------
        $this->add_action_buttons();
    }
}
