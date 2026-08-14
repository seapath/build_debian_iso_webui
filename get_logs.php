<?php
require_once 'config.php';
requireAuth();

header('Content-Type: application/json');

$buildId = $_GET['id'] ?? '';
$from = (int)($_GET['from'] ?? 0);

if (empty($buildId)) {
    echo json_encode(['error' => 'Build ID manquant']);
    exit;
}

$buildPath = BUILDS_PATH . '/' . $buildId;
$logFile = $buildPath . '/logs.txt';
$statusFile = $buildPath . '/status.txt';

$status = 'building';
if (file_exists($statusFile)) {
    $status = trim(file_get_contents($statusFile));
}

$content = '';
$size = $from;

if (file_exists($logFile)) {
    $size = filesize($logFile);
    if ($size > $from) {
        $fp = fopen($logFile, 'r');
        fseek($fp, $from);
        $content = fread($fp, $size - $from);
        fclose($fp);
    }
}

echo json_encode([
    'status' => $status,
    'content' => $content,
    'size' => $size
]);
?>
