<?php
require_once 'config.php';
requireAuth();

header('Content-Type: application/json');

$builds = [];
$dirs = glob(BUILDS_PATH . '/*', GLOB_ONLYDIR);

foreach ($dirs as $dir) {
    $buildId = basename($dir);
    $statusFile = $dir . '/status.txt';
    $configFile = $dir . '/config.json';
    
    $status = 'unknown';
    if (file_exists($statusFile)) {
        $status = trim(file_get_contents($statusFile));
    }
    
    $timestamp = '';
    $user = '';
    if (file_exists($configFile)) {
        $config = json_decode(file_get_contents($configFile), true);
        $timestamp = $config['timestamp'] ?? '';
        $user = $config['user'] ?? '';
    }
    
    $build = [
        'id' => $buildId,
        'status' => $status,
        'timestamp' => $timestamp,
        'user' => $user
    ];
    
    // Ajouter la position dans la file d'attente si le build est en attente
    if ($status === 'waiting') {
        $build['queue_position'] = getBuildQueuePosition($buildId);
    }
    
    $builds[] = $build;
}

// Trier par timestamp décroissant (plus récent en premier)
usort($builds, function($a, $b) {
    return strcmp($b['timestamp'], $a['timestamp']);
});

echo json_encode($builds);
?>
