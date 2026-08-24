<?php
session_start();
require_once __DIR__ . '/i18n.php';

// Répertoire racine où seront stockés :
// - les espaces de travail par session (clones du dépôt) - dans /tmp pour éviter les problèmes de permissions
// - les builds et artefacts générés
define('WORKSPACES_PATH', sys_get_temp_dir() . '/isobuilder_workspaces');
define('BUILDS_PATH', sys_get_temp_dir() . '/isobuilder_builds');

// Limite de builds simultanés (non encore utilisée finement, mais gardée pour extension)
define('MAX_CONCURRENT_BUILDS', 3);

// Fichier mutex pour les builds (un seul build à la fois)
define('BUILD_MUTEX_FILE', BUILDS_PATH . '/.build_mutex');
define('BUILD_QUEUE_FILE', BUILDS_PATH . '/.build_queue.json');

// URL du dépôt SEAPATH et branche à utiliser
define('SEAPATH_REPO_URL', 'https://github.com/seapath/build_debian_iso.git');
define('SEAPATH_REPO_BRANCH', 'main');

// Créer les répertoires de base s'ils n'existent pas
foreach ([WORKSPACES_PATH, BUILDS_PATH] as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log("Failed to create directory: $dir");
        }
    }
}

function generateBuildId() {
    return uniqid('build_', true);
}

function isAuthenticated() {
    return isset($_SESSION['user_id']);
}

function requireAuth() {
    if (!isAuthenticated()) {
        header('Location: index.php');
        exit;
    }
}

/**
 * Retourne le chemin de l'espace de travail pour l'utilisateur courant.
 * On isole les données par utilisateur (toutes les sessions d'un même utilisateur partagent le même workspace).
 */
function getSessionWorkspacePath(): string
{
    $user = $_SESSION['username'] ?? 'anonymous';
    // On évite les caractères bizarres dans le chemin
    $safeUser = preg_replace('/[^a-zA-Z0-9_-]/', '_', $user);

    $workspace = WORKSPACES_PATH . '/' . $safeUser;
    if (!is_dir($workspace)) {
        mkdir($workspace, 0755, true);
    }

    return $workspace;
}

/**
 * Retourne le chemin local du clone du dépôt SEAPATH pour l'utilisateur courant,
 * en le clonant si nécessaire. Le dépôt est partagé entre toutes les sessions d'un même utilisateur.
 * Le dépôt est mis à jour au login, pas ici.
 */
function getSessionRepoPath(): string
{
    $workspace = getSessionWorkspacePath();
    $repoPath = $workspace . '/build_debian_iso';

    // Si le dépôt n'existe pas encore, on le clone
    if (!is_dir($repoPath . '/.git')) {
        // Sécurité minimale : on s'assure que le dossier existe
        if (!is_dir($workspace)) {
            mkdir($workspace, 0755, true);
        }

        // Clone shallow de la branche voulue
        $cmd = sprintf(
            'cd %s && git clone --branch %s --depth 1 %s build_debian_iso 2>&1',
            escapeshellarg($workspace),
            escapeshellarg(SEAPATH_REPO_BRANCH),
            escapeshellarg(SEAPATH_REPO_URL)
        );
        shell_exec($cmd);
    }

    return $repoPath;
}

/**
 * Retourne le chemin du sous-répertoire usercustomization dans le dépôt
 * de la session courante. Il est créé si besoin.
 */
function getSessionUserCustomizationPath(): string
{
    $repoPath = getSessionRepoPath();
    $userCustomization = $repoPath . '/usercustomization';

    if (!is_dir($userCustomization)) {
        mkdir($userCustomization, 0755, true);
    }

    return $userCustomization;
}

/**
 * Vérifie si un build est actuellement en cours (mutex acquis)
 */
function isBuildRunning(): bool {
    if (!file_exists(BUILD_MUTEX_FILE)) {
        return false;
    }
    
    $mutexData = json_decode(file_get_contents(BUILD_MUTEX_FILE), true);
    if (!$mutexData || !isset($mutexData['build_id']) || !isset($mutexData['pid'])) {
        // Mutex invalide, le supprimer
        @unlink(BUILD_MUTEX_FILE);
        return false;
    }
    
    // Vérifier si le processus est toujours en vie
    $pid = (int)$mutexData['pid'];
    if ($pid <= 0) {
        @unlink(BUILD_MUTEX_FILE);
        return false;
    }
    
    // Vérifier si le processus existe (Linux/Unix)
    $result = shell_exec("ps -p $pid -o pid= 2>/dev/null");
    if (empty(trim($result))) {
        // Processus mort, mutex invalide
        @unlink(BUILD_MUTEX_FILE);
        return false;
    }
    
    // Vérifier que le fichier PID existe toujours dans le build
    $buildPath = BUILDS_PATH . '/' . $mutexData['build_id'];
    $pidFile = $buildPath . '/pid.txt';
    if (!file_exists($pidFile)) {
        // PID file manquant, mutex invalide
        @unlink(BUILD_MUTEX_FILE);
        return false;
    }
    
    return true;
}

/**
 * Acquiert le mutex pour un build donné
 */
function acquireBuildMutex(string $buildId, int $pid): bool {
    if (isBuildRunning()) {
        return false;
    }
    
    $mutexData = [
        'build_id' => $buildId,
        'pid' => $pid,
        'timestamp' => time()
    ];
    
    return file_put_contents(BUILD_MUTEX_FILE, json_encode($mutexData)) !== false;
}

/**
 * Libère le mutex
 */
function releaseBuildMutex(): void {
    @unlink(BUILD_MUTEX_FILE);
}

/**
 * Ajoute un build à la file d'attente
 */
function addBuildToQueue(string $buildId): void {
    $queue = getBuildQueue();
    $queue[] = [
        'build_id' => $buildId,
        'added_at' => time()
    ];
    file_put_contents(BUILD_QUEUE_FILE, json_encode($queue, JSON_PRETTY_PRINT));
}

/**
 * Récupère la file d'attente des builds
 */
function getBuildQueue(): array {
    if (!file_exists(BUILD_QUEUE_FILE)) {
        return [];
    }
    
    $content = file_get_contents(BUILD_QUEUE_FILE);
    $queue = json_decode($content, true);
    
    if (!is_array($queue)) {
        return [];
    }
    
    return $queue;
}

/**
 * Récupère le prochain build de la file d'attente
 */
function getNextBuildFromQueue(): ?string {
    $queue = getBuildQueue();
    if (empty($queue)) {
        return null;
    }
    
    $next = array_shift($queue);
    file_put_contents(BUILD_QUEUE_FILE, json_encode($queue, JSON_PRETTY_PRINT));
    
    return $next['build_id'] ?? null;
}

/**
 * Supprime un build de la file d'attente (par exemple en cas d'annulation)
 */
function removeBuildFromQueue(string $buildId): void {
    $queue = getBuildQueue();
    $queue = array_filter($queue, function($item) use ($buildId) {
        return ($item['build_id'] ?? '') !== $buildId;
    });
    file_put_contents(BUILD_QUEUE_FILE, json_encode(array_values($queue), JSON_PRETTY_PRINT));
}

/**
 * Récupère la position d'un build dans la file d'attente
 */
function getBuildQueuePosition(string $buildId): int {
    $queue = getBuildQueue();
    $position = 0;
    foreach ($queue as $item) {
        $position++;
        if (($item['build_id'] ?? '') === $buildId) {
            return $position;
        }
    }
    return 0; // Pas dans la file
}

/**
 * Extrait les package classes et flags de menu supportés par build_iso.sh
 * du dépôt cloné (listClasses / listFlags du mode --custom).
 *
 * @return array{package_classes: string[], menu_flags: string[]}
 */
function parseBuildIsoOptions(?string $repoPath = null): array
{
    $fallback = [
        'package_classes' => ['SEAPATH_CLUSTER', 'SEAPATH_DBG', 'SEAPATH_COCKPIT', 'SEAPATH_KERBEROS'],
        'menu_flags' => ['french', 'german', 'dbg', 'raid', 'cockpit', 'kerberos', 'cluster'],
    ];

    $repoPath = $repoPath ?? getSessionRepoPath();
    $scriptPath = $repoPath . '/build_iso.sh';
    if (!is_readable($scriptPath)) {
        return $fallback;
    }

    $content = file_get_contents($scriptPath);
    if ($content === false) {
        return $fallback;
    }

    $packageClasses = [];
    if (preg_match('/listClasses=\(([^)]*)\)/', $content, $m)) {
        // Format: "SEAPATH_CLUSTER" "ON" "SEAPATH_DBG" "ON" ...
        if (preg_match_all('/"([^"]+)"/', $m[1], $tokens)) {
            $values = $tokens[1];
            for ($i = 0; $i < count($values); $i += 2) {
                $name = $values[$i] ?? '';
                if ($name !== '' && $name !== 'ON' && $name !== 'OFF') {
                    $packageClasses[] = $name;
                }
            }
        }
    }

    $menuFlags = [];
    if (preg_match('/listFlags=\(([^)]*)\)/', $content, $m)) {
        // Format: "french" "description" "OFF" "german" "description" "OFF" ...
        if (preg_match_all('/"([^"]+)"/', $m[1], $tokens)) {
            $values = $tokens[1];
            for ($i = 0; $i < count($values); $i += 3) {
                $name = $values[$i] ?? '';
                if ($name !== '' && $name !== 'ON' && $name !== 'OFF') {
                    $menuFlags[] = $name;
                }
            }
        }
    }

    return [
        'package_classes' => $packageClasses ?: $fallback['package_classes'],
        'menu_flags' => $menuFlags ?: $fallback['menu_flags'],
    ];
}

/**
 * Normalise la cible de build (iso par défaut, pour rétrocompatibilité).
 */
function normalizeBuildTarget(?string $target): string
{
    return $target === 'qcow2' ? 'qcow2' : 'iso';
}

/**
 * Valide une taille de disque QCOW2 au format fai-diskimage (ex: 10G, 512M).
 */
function isValidVmDiskSize(string $size): bool
{
    return (bool) preg_match('/^\d+[KMGT]$/i', $size);
}

/**
 * Décrit l'artefact produit par un build (fichier, extension, nom de téléchargement).
 *
 * @return array{target: string, file: string, download_filename: string, extension: string}
 */
function getBuildArtifactInfo(string $buildPath, ?array $config = null): array
{
    if ($config === null) {
        $configFile = $buildPath . '/config.json';
        $config = [];
        if (is_readable($configFile)) {
            $decoded = json_decode((string) file_get_contents($configFile), true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }
    }

    $target = normalizeBuildTarget($config['target'] ?? null);
    $qcow2File = $buildPath . '/output.qcow2';
    $isoFile = $buildPath . '/output.iso';

    // Builds anciens sans champ target : on déduit du fichier présent
    if (!isset($config['target'])) {
        if (file_exists($qcow2File)) {
            $target = 'qcow2';
        } elseif (file_exists($isoFile)) {
            $target = 'iso';
        }
    }

    $buildId = basename($buildPath);
    if ($target === 'qcow2') {
        return [
            'target' => 'qcow2',
            'file' => $qcow2File,
            'download_filename' => 'debian-' . $buildId . '.qcow2',
            'extension' => 'qcow2',
        ];
    }

    return [
        'target' => 'iso',
        'file' => $isoFile,
        'download_filename' => 'debian-' . $buildId . '.iso',
        'extension' => 'iso',
    ];
}

/**
 * Indique si une valeur doit être entourée de quotes simples dans USERCUSTOMIZATION.var
 */
function needsVarFileQuoting(string $value): bool
{
    // Valeurs simples (hostname, IP, VLAN ID…) sans quotes
    return !preg_match('/^[a-zA-Z0-9._:\/-]+$/', $value);
}

/**
 * Formate une valeur pour l'écriture dans USERCUSTOMIZATION.var
 */
function formatVarFileValue(string $value): string
{
    if (!needsVarFileQuoting($value)) {
        return $value;
    }
    return "'" . str_replace("'", "'\\''", $value) . "'";
}

/**
 * Parse une valeur lue depuis USERCUSTOMIZATION.var (retire les quotes externes)
 */
function parseVarFileValue(string $value): string
{
    if (preg_match("/^'(.*)'$/s", $value, $matches)) {
        return str_replace("'\\''", "'", $matches[1]);
    }
    return $value;
}
?>
