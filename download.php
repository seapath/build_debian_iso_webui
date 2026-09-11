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

$config = loadBuildConfig($buildPath);
$artifact = getBuildArtifactInfo($buildPath, $config);
$downloadType = $_GET['type'] ?? 'disk';

if ($downloadType === 'xml') {
    if ($artifact['target'] !== 'qcow2' || empty($artifact['has_xml'])) {
        die('XML libvirt non disponible');
    }
    if (!file_exists($artifact['file'])) {
        die('QCOW2 non disponible');
    }

    $libvirt = getLibvirtOptionsFromConfig($config, $buildId);
    $xml = generateLibvirtDomainXml($libvirt);
    $filename = libvirtXmlDownloadFilename($libvirt, $buildId);

    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($xml));
    echo $xml;
    exit;
}

if (!file_exists($artifact['file'])) {
    die($artifact['target'] === 'qcow2' ? 'QCOW2 non disponible' : 'ISO non disponible');
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $artifact['download_filename'] . '"');
header('Content-Length: ' . filesize($artifact['file']));
readfile($artifact['file']);
exit;
