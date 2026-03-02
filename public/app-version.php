<?php
/**
 * Serves the app-version JSON (written by upload-apk.php).
 * GET /app-version is rewritten to this script via .htaccess.
 */
$file = __DIR__ . '/app-version';
if (!is_readable($file)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No version info']);
    exit;
}
header('Content-Type: application/json');
header('Cache-Control: no-store');
readfile($file);
