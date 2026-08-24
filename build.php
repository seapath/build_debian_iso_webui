<?php
require_once 'config.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$action = $_POST['action'] ?? 'save_and_build';

// Espace de travail / dépôt par utilisateur (partagé entre toutes les sessions)
// Le dépôt est mis à jour au login, pas besoin de le faire ici
$sessionRepoPath = getSessionRepoPath();
$sessionUserCustomizationPath = getSessionUserCustomizationPath();

// Ne créer le répertoire de build que si on lance vraiment une build
$buildId = null;
$buildPath = null;
if ($action !== 'save_only') {
    $buildId = generateBuildId();
    $buildPath = BUILDS_PATH . '/' . $buildId;
    // Créer le répertoire de build (pour logs, artefacts, etc.)
    if (!is_dir($buildPath)) {
        mkdir($buildPath, 0755, true);
    }
}

// Récupérer les paramètres du formulaire
$hostname = $_POST['hostname'] ?? 'seapath-host';
$USERPW = $_POST['USERPW'] ?? '';
$myrootkey = $_POST['myrootkey'] ?? '';
$myuserkey = $_POST['myuserkey'] ?? '';
$ansiblekey = $_POST['ansiblekey'] ?? '';
$REMOTENIC = $_POST['REMOTENIC'] ?? '';
$REMOTEADDR = $_POST['REMOTEADDR'] ?? '';
$REMOTEGW = $_POST['REMOTEGW'] ?? '';
$REMOTEVLANID = $_POST['REMOTEVLANID'] ?? '';

// Créer le répertoire class s'il n'existe pas
$classDir = $sessionUserCustomizationPath . '/class';
if (!is_dir($classDir)) {
    mkdir($classDir, 0755, true);
}

// Chemin du fichier USERCUSTOMIZATION.var
$varFile = $classDir . '/USERCUSTOMIZATION.var';

// Lire le contenu existant si le fichier existe
$existingContent = '';
$vars = [];
if (file_exists($varFile)) {
    $existingContent = file_get_contents($varFile);
    // Parser les variables existantes (format VAR=value)
    $lines = explode("\n", $existingContent);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === '#') continue;
        // Matcher les noms de variables (lettres, chiffres, underscores, minuscules et majuscules)
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)=(.*)$/', $line, $matches)) {
            $vars[$matches[1]] = parseVarFileValue($matches[2]);
        }
    }
}

// Mettre à jour les variables du formulaire
// Si une valeur est non vide, on la met à jour
// Si une valeur est vide, on supprime la clé (sauf HOSTNAME qui est requis)
// Supprimer l'ancienne clé 'hostname' en minuscule si elle existe (migration vers HOSTNAME)
unset($vars['hostname']);
if (!empty($hostname)) {
    $vars['HOSTNAME'] = $hostname;
}
// Note: HOSTNAME est requis, donc on ne le supprime jamais

// Pour les autres champs, supprimer la clé si la valeur est vide
if (!empty(trim($USERPW))) {
    $userpwValue = trim($USERPW);
    $vars['USERPW'] = $userpwValue;
    // USERPW et ROOTPW doivent avoir la même valeur
    $vars['ROOTPW'] = $userpwValue;
} else {
    unset($vars['USERPW']);
    unset($vars['ROOTPW']);
}

if (!empty(trim($myrootkey))) {
    $vars['myrootkey'] = trim($myrootkey);
} else {
    unset($vars['myrootkey']);
}

if (!empty(trim($myuserkey))) {
    $vars['myuserkey'] = trim($myuserkey);
} else {
    unset($vars['myuserkey']);
}

if (!empty(trim($ansiblekey))) {
    $vars['ansiblekey'] = trim($ansiblekey);
} else {
    unset($vars['ansiblekey']);
}

if (!empty(trim($REMOTENIC))) {
    $vars['REMOTENIC'] = trim($REMOTENIC);
} else {
    unset($vars['REMOTENIC']);
}

if (!empty(trim($REMOTEADDR))) {
    $vars['REMOTEADDR'] = trim($REMOTEADDR);
} else {
    unset($vars['REMOTEADDR']);
}

if (!empty(trim($REMOTEGW))) {
    $vars['REMOTEGW'] = trim($REMOTEGW);
} else {
    unset($vars['REMOTEGW']);
}

if (!empty(trim($REMOTEVLANID))) {
    $vars['REMOTEVLANID'] = trim($REMOTEVLANID);
} else {
    unset($vars['REMOTEVLANID']);
}

// Écrire le fichier avec toutes les variables
$newContent = '';
foreach ($vars as $key => $value) {
    $newContent .= $key . '=' . formatVarFileValue($value) . "\n";
}
file_put_contents($varFile, $newContent);

// Sauvegarder les options de build (package classes et menu items) pour l'export/import
$packageClasses = $_POST['package_classes'] ?? [];
if (empty($packageClasses) || !is_array($packageClasses)) {
    $packageClasses = ['SEAPATH_CLUSTER'];
}
$menuItemsJson = $_POST['menu_items_json'] ?? '[["french","cluster"]]';
$menuItemsLists = json_decode($menuItemsJson, true);
if (empty($menuItemsLists) || !is_array($menuItemsLists)) {
    $menuItemsLists = [['french', 'cluster']];
}

$target = normalizeBuildTarget($_POST['target'] ?? 'iso');
$vmDiskSize = trim((string) ($_POST['vmdisksize'] ?? '10G'));
if (!isValidVmDiskSize($vmDiskSize)) {
    $vmDiskSize = '10G';
}
$cloudInit = !empty($_POST['cloud_init']);

$buildOptionsFile = $sessionUserCustomizationPath . '/build_options.json';
$buildOptions = [
    'target' => $target,
    'package_classes' => $packageClasses,
    'menu_items' => $menuItemsLists,
    'vmdisksize' => $vmDiskSize,
    'cloud_init' => $cloudInit,
];
file_put_contents($buildOptionsFile, json_encode($buildOptions, JSON_PRETTY_PRINT));

// Si on veut juste sauvegarder sans lancer la build
if ($action === 'save_only') {
    header('Location: dashboard.php?saved=1');
    exit;
}

// Créer un fichier de configuration JSON pour référence (seulement si on lance une build)
$config = [
    'build_id' => $buildId,
    'user' => $_SESSION['username'],
    'timestamp' => date('Y-m-d H:i:s'),
    'hostname' => $hostname,
    'target' => $target,
];
file_put_contents($buildPath . '/config.json', json_encode($config, JSON_PRETTY_PRINT));

// Préparer la commande de build
$logFile = $buildPath . '/logs.txt';
$statusFile = $buildPath . '/status.txt';
$pidFile = $buildPath . '/pid.txt';

// Récupérer les classes de packages et les items de menu (déjà récupérés plus haut pour sauvegarde)
// Réutiliser les variables définies précédemment

// Construire les arguments pour build_iso.sh
$classesArg = '';
if (!empty($packageClasses) && is_array($packageClasses)) {
    // Joindre les classes avec des virgules (pas besoin d'échapper individuellement, on échappera la chaîne finale)
    $classesArg = implode(',', $packageClasses);
}

// Parser les menu items JSON
$menuItemsLists = json_decode($menuItemsJson, true);
$menuArg = '';
if (!empty($menuItemsLists) && is_array($menuItemsLists)) {
    $menuLists = [];
    foreach ($menuItemsLists as $list) {
        if (!empty($list) && is_array($list)) {
            // Joindre les items de la liste avec des virgules
            $menuLists[] = implode(',', $list);
        }
    }
    if (!empty($menuLists)) {
        // Joindre les listes avec des points-virgules
        $menuArg = implode(';', $menuLists);
    }
}

// Si aucun menu n'est fourni, utiliser la valeur par défaut
if (empty($menuArg)) {
    $menuArg = 'french,cluster';
}

// Construire la commande selon la cible (ISO ou QCOW2)
if ($target === 'qcow2') {
    $buildCmd = './build_qcow2.sh --vmdisksize ' . escapeshellarg($vmDiskSize);
    if ($cloudInit) {
        $buildCmd .= ' --cloud-init';
    }
    $outputFile = $buildPath . '/output.qcow2';
    $artifactGlob = '*.qcow2';
} else {
    $buildCmd = './build_iso.sh';
    if (!empty($classesArg)) {
        $buildCmd .= ' --classes ' . escapeshellarg($classesArg);
    }
    if (!empty($menuArg)) {
        $buildCmd .= ' --menu ' . escapeshellarg($menuArg);
    }
    $outputFile = $buildPath . '/output.iso';
    $artifactGlob = '*.iso';
}

// Chemin absolu vers le script PHP pour démarrer le prochain build
$startNextBuildScript = __DIR__ . '/start_next_build.php';

// Créer un script shell qui va exécuter la build dans le dépôt de la session
$scriptPath = $buildPath . '/run_build.sh';
$scriptContent = <<<BASH
#!/bin/bash
cd {$sessionRepoPath}

# Lancer la build avec les arguments
{$buildCmd} > {$logFile} 2>&1

# Enregistrer le code de sortie
EXIT_CODE=\$?
echo \$EXIT_CODE > {$buildPath}/exit_code.txt

# Copier l'artefact généré si la build a réussi
if [ \$EXIT_CODE -eq 0 ]; then
    find {$sessionRepoPath} -name "{$artifactGlob}" -type f -mmin -10 -exec mv {} {$outputFile} \;
    echo "completed" > {$statusFile}
else
    echo "failed" > {$statusFile}
fi

rm {$pidFile}

# Libérer le mutex et démarrer le prochain build de la file d'attente
php {$startNextBuildScript}
BASH;

file_put_contents($scriptPath, $scriptContent);
chmod($scriptPath, 0755);

// Vérifier si un build est déjà en cours
if (isBuildRunning()) {
    // Ajouter à la file d'attente
    addBuildToQueue($buildId);
    file_put_contents($statusFile, 'waiting');
    
    // Rediriger vers le dashboard avec un message
    header("Location: dashboard.php?queued=1");
    exit;
}

// Aucun build en cours, on peut démarrer immédiatement
// Marquer comme "building"
file_put_contents($statusFile, 'building');

// Lancer la build en arrière-plan
$cmd = "nohup {$scriptPath} > /dev/null 2>&1 & echo $!";
$pid = shell_exec($cmd);
$pid = trim($pid);
file_put_contents($pidFile, $pid);

// Acquérir le mutex
acquireBuildMutex($buildId, (int)$pid);

// Rediriger vers la page de logs
header("Location: stream_logs.php?id=" . urlencode($buildId));
exit;
?>
