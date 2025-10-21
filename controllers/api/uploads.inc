<?php
// controllers/api/uploads.inc

// Optional: reply to CORS preflight
function options($q){
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    render_json(['ok'=>1]);
}

function post($q){
    // Expect multipart/form-data with input name "file"
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        render_json(['error' => 'no_file'], 400);
        return;
    }

    $tmp  = $_FILES['file']['tmp_name'];
    $name = $_FILES['file']['name'] ?? ('img_' . time());
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'jpg');

    // Basic size/type checks
    if ($_FILES['file']['size'] > 8 * 1024 * 1024) { // 8MB
        render_json(['error' => 'too_large'], 413);
        return;
    }
    // Try to detect mime type
    try { $info = @getimagesize($tmp); } catch (Exception $e) { $info = false; }
    if (!$info) {
        // Not an image (or unreadable)
        render_json(['error' => 'invalid_image'], 415);
        return;
    }

    // S3 key: luis_uploads/YYYY/MM/<random>.<ext>
    $s3Key = 'luis_uploads/' . date('Y/m/') . bin2hex(random_bytes(8)) . '.' . $ext;

    // Upload to S3
    $url = Amazon::s3upload($tmp, $s3Key, [], Conf::$aws_s3_bucket, 'public-read');

    if (!$url) {
        render_json(['error' => 'upload_failed'], 500);
        return;
    }

    render_json(['url' => $url, 'key' => $s3Key]);
}