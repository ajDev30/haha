<?php
require_once(__DIR__ . '/../../config.php');
require_login();
if (isguestuser()) { print_error('noguest'); }

$id = required_param('id', PARAM_INT);
$attempt = $DB->get_record('readingassessment_ext_att', ['id' => $id]);
if (!$attempt || empty($attempt->audio_path)) {
    header("HTTP/1.0 404 Not Found");
    exit;
}

$file = $attempt->audio_path;
if (file_exists($file)) {
    $mime = mime_content_type($file);
    if (!$mime) $mime = 'audio/webm';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($file));
    readfile($file);
} else {
    header("HTTP/1.0 404 Not Found");
}
