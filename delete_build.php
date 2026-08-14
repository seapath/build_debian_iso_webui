<?php
require_once 'config.php';
requireAuth();

$buildId = $_GET['id'] ?? '';
if (empty($buildId)) {
    header('Location: dashboard.php?error=build_id_missing');
    exit;
}

$buildPath = BUILDS_PATH . '/' . $buildId;
$configFile = $buildPath . '/config.json';
$statusFile = $buildPath . '/status.txt';

// Vérifier que la build existe
if (!is_dir($buildPath)) {
    header('Location: dashboard.php?error=build_not_found');
    exit;
}

// Vérifier que la build appartient à l'utilisateur actuel
$isUserBuild = false;
if (file_exists($configFile)) {
    $config = json_decode(file_get_contents($configFile), true);
    if (isset($config['user']) && $config['user'] === $_SESSION['username']) {
        $isUserBuild = true;
    }
}

if (!$isUserBuild) {
    header('Location: dashboard.php?error=build_not_authorized');
    exit;
}

// Lire le statut
$status = 'unknown';
if (file_exists($statusFile)) {
    $status = trim(file_get_contents($statusFile));
}

// Si la build est en attente, la retirer de la file d'attente
if ($status === 'waiting') {
    removeBuildFromQueue($buildId);
}

// Si la build est en cours, tuer le processus (sécurité)
if ($status === 'building') {
    $pidFile = $buildPath . '/pid.txt';
    if (file_exists($pidFile)) {
        $pid = trim(file_get_contents($pidFile));
        if (!empty($pid) && is_numeric($pid)) {
            // Tuer le processus et ses enfants
            @exec("pkill -P {$pid} 2>/dev/null");
            @exec("kill {$pid} 2>/dev/null");
        }
    }
    // Libérer le mutex si c'était ce build qui était en cours
    $mutexData = null;
    if (file_exists(BUILD_MUTEX_FILE)) {
        $mutexData = json_decode(file_get_contents(BUILD_MUTEX_FILE), true);
        if ($mutexData && isset($mutexData['build_id']) && $mutexData['build_id'] === $buildId) {
            releaseBuildMutex();
        }
    }
}

// Supprimer le répertoire de build
require_once 'auth.php'; // Pour avoir accès à deleteDirectory
deleteDirectory($buildPath);

header('Location: dashboard.php?deleted=1');
exit;
?>
