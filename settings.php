<?php
defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    // This adds a settings page under Plugins -> Activity modules -> Reading Assessment
    $settings->add(new admin_setting_heading('readingassessment_heading', 'Reading Assessment Settings', 'Configure the Phil-IRI Reading Assessment system.'));
    
    // To add a direct link to the external manage dashboard
    $url = new moodle_url('/mod/readingassessment/external_manage.php');
    $link = html_writer::link($url, 'Open Phil-IRI Teacher Dashboard');
    
    $settings->add(new admin_setting_heading('readingassessment_dashboard_link', '', $link));
}
