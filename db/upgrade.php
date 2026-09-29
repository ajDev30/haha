<?php
// This file is part of Moodle - http://moodle.org/
defined('MOODLE_INTERNAL') || die();

/**
 * Post-test database upgrade script.
 * Creates the new post-test tables when upgrading from older versions.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_readingassessment_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026092701) {
        // --- readingassessment_post_cfg ---
        $table = new xmldb_table('readingassessment_post_cfg');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('passage_title', XMLDB_TYPE_CHAR, '255');
            $table->add_field('passage_text', XMLDB_TYPE_TEXT);
            $table->add_field('passage_instructions', XMLDB_TYPE_TEXT);
            $table->add_field('questions_json', XMLDB_TYPE_TEXT);
            $table->add_field('tts_personality', XMLDB_TYPE_TEXT);
            $table->add_field('tts_voice', XMLDB_TYPE_CHAR, '50', null, null, null, 'alloy');
            $table->add_field('mastery_threshold', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '80');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('cmid', XMLDB_INDEX_UNIQUE, ['cmid']);
            $dbman->create_table($table);
        }

        // --- readingassessment_post_att ---
        $table = new xmldb_table('readingassessment_post_att');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('cfgid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('audio_file_id', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('transcript_raw', XMLDB_TYPE_TEXT);
            $table->add_field('transcript_markup', XMLDB_TYPE_TEXT);
            $table->add_field('azure_json', XMLDB_TYPE_TEXT);
            $table->add_field('assessment_json', XMLDB_TYPE_TEXT);
            $table->add_field('accuracy', XMLDB_TYPE_NUMBER, '6,2', null, null, null, '0.00');
            $table->add_field('wpm', XMLDB_TYPE_NUMBER, '8,2', null, null, null, '0.00');
            $table->add_field('comprehension', XMLDB_TYPE_NUMBER, '6,2', null, null, null, '0.00');
            $table->add_field('comp_earned', XMLDB_TYPE_NUMBER, '8,2', null, null, null, '0.00');
            $table->add_field('comp_total', XMLDB_TYPE_NUMBER, '8,2', null, null, null, '0.00');
            $table->add_field('timestarted', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_cfgid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'cfgid']);
            $dbman->create_table($table);
        }

        // --- readingassessment_post_resp ---
        $table = new xmldb_table('readingassessment_post_resp');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('attid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('answer_json', XMLDB_TYPE_TEXT);
            $table->add_field('earned', XMLDB_TYPE_NUMBER, '8,2', null, null, null, '0.00');
            $table->add_field('possible', XMLDB_TYPE_NUMBER, '8,2', null, null, null, '0.00');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // --- readingassessment_post_coach ---
        $table = new xmldb_table('readingassessment_post_coach');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('attid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('target_word', XMLDB_TYPE_CHAR, '255');
            $table->add_field('phonemes_json', XMLDB_TYPE_TEXT);
            $table->add_field('stage', XMLDB_TYPE_CHAR, '50');
            $table->add_field('azure_json', XMLDB_TYPE_TEXT);
            $table->add_field('score', XMLDB_TYPE_NUMBER, '6,2', null, null, null, '0.00');
            $table->add_field('mastered', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026092701, 'readingassessment');
    }

    if ($oldversion < 2026092901) {
        $table = new xmldb_table('readingassessment');
        $field = new xmldb_field('attempts_allowed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'introformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026092901, 'readingassessment');
    }

    if ($oldversion < 2026092903) {
        // --- readingassessment_aral_set ---
        $table = new xmldb_table('readingassessment_aral_set');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('pre_score', XMLDB_TYPE_NUMBER, '6, 2', null, null, null, null);
            $table->add_field('pre_reading_level', XMLDB_TYPE_CHAR, '50', null, null, null, null);
            $table->add_field('calc_method', XMLDB_TYPE_CHAR, '50', null, null, null, null);
            $table->add_field('prog_calc_method', XMLDB_TYPE_CHAR, '50', null, null, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_courseid', XMLDB_INDEX_UNIQUE, ['userid', 'courseid']);

            $dbman->create_table($table);
        }

        // --- readingassessment_aral_act ---
        $table = new xmldb_table('readingassessment_aral_act');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('activity_name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('activity_date', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('assessment_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('total_items', XMLDB_TYPE_NUMBER, '8, 2', null, null, null, null);
            $table->add_field('correct_answers', XMLDB_TYPE_NUMBER, '8, 2', null, null, null, null);
            $table->add_field('percentage_score', XMLDB_TYPE_NUMBER, '6, 2', null, null, null, null);
            $table->add_field('weight', XMLDB_TYPE_NUMBER, '6, 2', null, null, null, null);
            $table->add_field('oral_total_words', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('oral_miscues', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('oral_time_seconds', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_courseid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);

            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026092903, 'readingassessment');
    }

    return true;
}
