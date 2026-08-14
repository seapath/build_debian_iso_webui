<?php
require_once 'config.php';
requireAuth();

$buildId = $_GET['id'] ?? '';
if (empty($buildId)) {
    die('Build ID manquant');
}

$buildPath = BUILDS_PATH . '/' . $buildId;
$isoFile = $buildPath . '/output.iso';

if (!file_exists($isoFile)) {
    die('ISO non disponible');
}

// Envoyer le fichier
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="debian-' . $buildId . '.iso"');
header('Content-Length: ' . filesize($isoFile));
readfile($isoFile);
exit;
?>
