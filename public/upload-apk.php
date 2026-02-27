<?php
/**
 * In-app update: upload APK and update app-version JSON.
 * POST to http://38.226.41.4:9000/upload-apk.php (multipart/form-data):
 *   apk, latest_version, latest_version_name, apk_url, force_update, release_notes
 */
header('Content-Type: application/json');

$uploadDir = __DIR__ . '/apk';
$versionFile = __DIR__ . '/app-version';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$version = isset($_POST['latest_version']) ? (int) $_POST['latest_version'] : 0;
$versionName = isset($_POST['latest_version_name']) ? (string) $_POST['latest_version_name'] : '';
$apkUrl = isset($_POST['apk_url']) ? (string) $_POST['apk_url'] : '';
$forceUpdate = isset($_POST['force_update']) && ($_POST['force_update'] === 'true' || $_POST['force_update'] === '1');
$releaseNotes = isset($_POST['release_notes']) ? (string) $_POST['release_notes'] : '';

if (!isset($_FILES['apk']) || $_FILES['apk']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No APK file or upload error']);
    exit;
}

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$apkPath = $uploadDir . '/app-release.apk';
if (!move_uploaded_file($_FILES['apk']['tmp_name'], $apkPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save APK']);
    exit;
}

$payload = [
    'latest_version' => $version,
    'latest_version_name' => $versionName,
    'apk_url' => $apkUrl,
    'force_update' => $forceUpdate,
    'release_notes' => $releaseNotes,
];
$json = json_encode($payload, JSON_UNESCAPED_SLASHES);
if (file_put_contents($versionFile, $json) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to write app-version']);
    exit;
}

http_response_code(200);
echo json_encode(['ok' => true, 'version' => $versionName, 'version_code' => $version]);
