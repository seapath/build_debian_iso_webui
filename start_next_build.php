<?php
/**
 * Script CLI pour démarrer le prochain build de la file d'attente.
 * Appelé depuis run_build.sh après qu'un build se termine.
 */

// Charger les fonctions nécessaires (config.php gère les sessions automatiquement)
require_once __DIR__ . '/config.php';

// Libérer le mutex du build précédent (au cas où il ne serait pas déjà libéré)
releaseBuildMutex();

// Récupérer le prochain build de la file
$nextBuildId = getNextBuildFromQueue();

if ($nextBuildId === null) {
    // Aucun build en attente
    exit(0);
}

$buildPath = BUILDS_PATH . '/' . $nextBuildId;
$scriptPath = $buildPath . '/run_build.sh';
$statusFile = $buildPath . '/status.txt';
$pidFile = $buildPath . '/pid.txt';

// Vérifier que le script existe
if (!file_exists($scriptPath)) {
    error_log("start_next_build.php: Script run_build.sh non trouvé pour build $nextBuildId");
    exit(1);
}

// Vérifier qu'aucun build n'est déjà en cours (sécurité)
if (isBuildRunning()) {
    // Remettre le build dans la file d'attente
    addBuildToQueue($nextBuildId);
    error_log("start_next_build.php: Build déjà en cours, remis en file: $nextBuildId");
    exit(1);
}

// Mettre à jour le statut
file_put_contents($statusFile, 'building');

// Lancer le script en arrière-plan
$cmd = "nohup {$scriptPath} > /dev/null 2>&1 & echo $!";
$pid = shell_exec($cmd);
$pid = trim($pid);

if (empty($pid)) {
    error_log("start_next_build.php: Échec du démarrage du build $nextBuildId");
    file_put_contents($statusFile, 'failed');
    exit(1);
}

// Enregistrer le PID
file_put_contents($pidFile, $pid);

// Acquérir le mutex
acquireBuildMutex($nextBuildId, (int)$pid);

exit(0);
?>
