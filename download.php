<?php
require_once 'config.php';
requireAuth();

$buildId = $_GET['id'] ?? '';
if (empty($buildId)) {
    die('Build ID manquant');
}

$buildPath = BUILDS_PATH . '/' . $buildId;
if (!is_dir($buildPath)) {
    die('Build introuvable');
}

$artifact = getBuildArtifactInfo($buildPath);
if (!file_exists($artifact['file'])) {
    die($artifact['target'] === 'qcow2' ? 'QCOW2 non disponible' : 'ISO non disponible');
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $artifact['download_filename'] . '"');
header('Content-Length: ' . filesize($artifact['file']));
readfile($artifact['file']);
exit;
?>
