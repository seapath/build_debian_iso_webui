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

// URL du dépôt SEAPATH et branche/tag par défaut (surchargeable depuis le dashboard)
define('SEAPATH_REPO_URL', 'https://github.com/seapath/build_debian_iso.git');
define('SEAPATH_REPO_BRANCH', 'main');
define('SEAPATH_REFS_CACHE_TTL', 300);

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

/**
 * Supprime récursivement un répertoire et son contenu
 */
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return;
    }

    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Vérifie qu'un nom de branche ou tag git est sûr à passer en argument.
 */
function isValidRepoRefName(string $ref): bool
{
    return (bool) preg_match('/^[A-Za-z0-9._\/-]+$/', $ref);
}

function getSelectedRepoRefFile(): string
{
    return getSessionWorkspacePath() . '/.seapath_ref';
}

/**
 * Branche ou tag actuellement sélectionné pour le clone build_debian_iso.
 */
function getSelectedRepoRef(): string
{
    $file = getSelectedRepoRefFile();
    if (is_readable($file)) {
        $ref = trim((string) file_get_contents($file));
        if (isValidRepoRefName($ref)) {
            return $ref;
        }
    }

    return SEAPATH_REPO_BRANCH;
}

function setSelectedRepoRef(string $ref): void
{
    if (!isValidRepoRefName($ref)) {
        return;
    }
    file_put_contents(getSelectedRepoRefFile(), $ref);
}

/**
 * Liste les branches et tags distants de build_debian_iso (cache 5 min).
 *
 * @return array{branches: string[], tags: string[]}
 */
function listSeapathRepoRefs(bool $forceRefresh = false): array
{
    $empty = ['branches' => [], 'tags' => []];
    $cacheFile = WORKSPACES_PATH . '/.refs_cache.json';

    if (!$forceRefresh && is_readable($cacheFile)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)
            && ($cached['fetched_at'] ?? 0) > time() - SEAPATH_REFS_CACHE_TTL
            && isset($cached['refs']['branches'], $cached['refs']['tags'])
        ) {
            return $cached['refs'];
        }
    }

    $cmd = sprintf(
        'GIT_TERMINAL_PROMPT=0 git -c http.lowSpeedLimit=1 -c http.lowSpeedTime=20 ls-remote --heads --tags %s 2>/dev/null',
        escapeshellarg(SEAPATH_REPO_URL)
    );
    $output = (string) shell_exec($cmd);
    if ($output === '') {
        return $empty;
    }

    $branches = [];
    $tags = [];
    foreach (explode("\n", $output) as $line) {
        if (!preg_match('/^[0-9a-f]+\s+refs\/(heads|tags)\/(\S+)$/i', $line, $m)) {
            continue;
        }
        $name = $m[2];
        if (str_ends_with($name, '^{}')) {
            continue;
        }
        if (!isValidRepoRefName($name)) {
            continue;
        }
        if ($m[1] === 'heads') {
            $branches[] = $name;
        } else {
            $tags[] = $name;
        }
    }

    $branches = array_values(array_unique($branches));
    $tags = array_values(array_unique($tags));

    usort($branches, static function (string $a, string $b): int {
        if ($a === 'main') {
            return -1;
        }
        if ($b === 'main') {
            return 1;
        }
        if ($a === 'master') {
            return -1;
        }
        if ($b === 'master') {
            return 1;
        }
        return strcasecmp($a, $b);
    });

    usort($tags, static function (string $a, string $b): int {
        return version_compare($b, $a);
    });

    $refs = ['branches' => $branches, 'tags' => $tags];
    @file_put_contents($cacheFile, json_encode([
        'fetched_at' => time(),
        'refs' => $refs,
    ], JSON_PRETTY_PRINT));

    return $refs;
}

/**
 * Reclone le dépôt sur la ref demandée en conservant usercustomization/.
 */
function checkoutSeapathRepoRef(string $ref): bool
{
    if (!isValidRepoRefName($ref)) {
        return false;
    }

    $workspace = getSessionWorkspacePath();
    $repoPath = $workspace . '/build_debian_iso';
    $newRepoPath = $workspace . '/build_debian_iso.new';

    if (is_dir($newRepoPath)) {
        deleteDirectory($newRepoPath);
    }

    $cmd = sprintf(
        'GIT_TERMINAL_PROMPT=0 git -c http.lowSpeedLimit=1 -c http.lowSpeedTime=60 clone --branch %s --depth 1 %s %s 2>&1',
        escapeshellarg($ref),
        escapeshellarg(SEAPATH_REPO_URL),
        escapeshellarg($newRepoPath)
    );
    shell_exec($cmd);

    if (!is_dir($newRepoPath . '/.git')) {
        if (is_dir($newRepoPath)) {
            deleteDirectory($newRepoPath);
        }
        return false;
    }

    $oldCustom = $repoPath . '/usercustomization';
    $newCustom = $newRepoPath . '/usercustomization';
    if (is_dir($oldCustom)) {
        if (is_dir($newCustom)) {
            deleteDirectory($newCustom);
        }
        shell_exec(sprintf(
            'cp -a %s %s',
            escapeshellarg($oldCustom),
            escapeshellarg($newCustom)
        ));
        if (!is_dir($newCustom)) {
            deleteDirectory($newRepoPath);
            return false;
        }
    }

    if (is_dir($repoPath)) {
        deleteDirectory($repoPath);
    }
    if (!@rename($newRepoPath, $repoPath)) {
        return false;
    }

    setSelectedRepoRef($ref);
    return true;
}

function repoSupportsQcow2(?string $repoPath = null): bool
{
    $repoPath = $repoPath ?? getSessionRepoPath();
    return is_file($repoPath . '/build_qcow2.sh');
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

        // Clone shallow de la branche/tag sélectionné
        $ref = getSelectedRepoRef();
        $cmd = sprintf(
            'GIT_TERMINAL_PROMPT=0 git -c http.lowSpeedLimit=1 -c http.lowSpeedTime=60 clone --branch %s --depth 1 %s %s 2>&1',
            escapeshellarg($ref),
            escapeshellarg(SEAPATH_REPO_URL),
            escapeshellarg($repoPath)
        );
        shell_exec($cmd);
        if (is_dir($repoPath . '/.git')) {
            setSelectedRepoRef($ref);
        }
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

function isValidLibvirtDomainName(string $name): bool
{
    return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,62}$/', $name);
}

function isValidLibvirtMacAddress(string $mac): bool
{
    return (bool) preg_match('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $mac);
}

function isValidLibvirtUuid(string $uuid): bool
{
    return (bool) preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid);
}

function isValidLibvirtBridgeName(string $name): bool
{
    return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,14}$/', $name);
}

function isValidLibvirtDiskPath(string $path): bool
{
    if (str_contains($path, '..')) {
        return false;
    }
    return (bool) preg_match('#^/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+\.qcow2$#', $path);
}

function generateLibvirtMacAddress(): string
{
    $bytes = random_bytes(3);
    return sprintf('52:54:00:%02x:%02x:%02x', ord($bytes[0]), ord($bytes[1]), ord($bytes[2]));
}

function generateLibvirtUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function uuidFromSeed(string $seed): string
{
    $hex = md5($seed);
    $hex[12] = '4';
    $variants = ['8', '9', 'a', 'b'];
    $hex[16] = $variants[hexdec($hex[16]) % 4];
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function macFromSeed(string $seed): string
{
    $hex = md5($seed);
    return sprintf(
        '52:54:00:%s:%s:%s',
        substr($hex, 0, 2),
        substr($hex, 2, 2),
        substr($hex, 4, 2)
    );
}

function defaultLibvirtDiskPath(string $domainName): string
{
    return '/var/lib/libvirt/images/' . $domainName . '.qcow2';
}

function libvirtTruthy($value): bool
{
    return $value === true || $value === 1 || $value === '1' || $value === 'on';
}

/**
 * Options libvirt par défaut, alignées sur guest.xml.j2 (VM non-RT).
 *
 * @return array{
 *   name: string,
 *   uuid: string,
 *   vcpu: int,
 *   memory: int,
 *   bridge: string,
 *   mac: string,
 *   disk_path: string,
 *   secure_boot: bool,
 *   graphic_console: bool,
 *   memballoon: bool
 * }
 */
function defaultLibvirtOptions(string $hostname, ?string $stableSeed = null): array
{
    $name = isValidLibvirtDomainName($hostname) ? $hostname : 'seapath-vm';
    return [
        'name' => $name,
        'uuid' => $stableSeed ? uuidFromSeed($stableSeed) : generateLibvirtUuid(),
        'vcpu' => 1,
        'memory' => 2048,
        'bridge' => 'br0',
        'mac' => $stableSeed ? macFromSeed($stableSeed) : generateLibvirtMacAddress(),
        'disk_path' => defaultLibvirtDiskPath($name),
        'secure_boot' => true,
        'graphic_console' => false,
        'memballoon' => false,
    ];
}

/**
 * Normalise les champs libvirt (formulaire, build_options.json ou config.json).
 *
 * @param array<string, mixed> $input
 * @return array{
 *   name: string,
 *   uuid: string,
 *   vcpu: int,
 *   memory: int,
 *   bridge: string,
 *   mac: string,
 *   disk_path: string,
 *   secure_boot: bool,
 *   graphic_console: bool,
 *   memballoon: bool
 * }
 */
function normalizeLibvirtOptions(array $input, string $fallbackHostname, ?string $stableSeed = null): array
{
    $defaults = defaultLibvirtOptions($fallbackHostname, $stableSeed);

    $name = trim((string) ($input['name'] ?? ''));
    if (!isValidLibvirtDomainName($name)) {
        $name = $defaults['name'];
    }

    $vcpu = (int) ($input['vcpu'] ?? $defaults['vcpu']);
    if ($vcpu < 1 || $vcpu > 128) {
        $vcpu = $defaults['vcpu'];
    }

    $memory = (int) ($input['memory'] ?? $defaults['memory']);
    if ($memory < 128 || $memory > 1048576) {
        $memory = $defaults['memory'];
    }

    $bridge = trim((string) ($input['bridge'] ?? ''));
    if (!isValidLibvirtBridgeName($bridge)) {
        $bridge = $defaults['bridge'];
    }

    $mac = strtolower(trim((string) ($input['mac'] ?? '')));
    if (!isValidLibvirtMacAddress($mac)) {
        $mac = $defaults['mac'];
    }

    $diskPath = trim((string) ($input['disk_path'] ?? ''));
    if (!isValidLibvirtDiskPath($diskPath)) {
        $diskPath = defaultLibvirtDiskPath($name);
    }

    $uuid = trim((string) ($input['uuid'] ?? ''));
    if (!isValidLibvirtUuid($uuid)) {
        $uuid = $defaults['uuid'];
    }

    return [
        'name' => $name,
        'uuid' => $uuid,
        'vcpu' => $vcpu,
        'memory' => $memory,
        'bridge' => $bridge,
        'mac' => $mac,
        'disk_path' => $diskPath,
        'secure_boot' => array_key_exists('secure_boot', $input)
            ? libvirtTruthy($input['secure_boot'])
            : $defaults['secure_boot'],
        'graphic_console' => array_key_exists('graphic_console', $input)
            ? libvirtTruthy($input['graphic_console'])
            : $defaults['graphic_console'],
        'memballoon' => array_key_exists('memballoon', $input)
            ? libvirtTruthy($input['memballoon'])
            : $defaults['memballoon'],
    ];
}

/**
 * @param array<string, mixed> $post
 * @return array{
 *   name: string,
 *   uuid: string,
 *   vcpu: int,
 *   memory: int,
 *   bridge: string,
 *   mac: string,
 *   disk_path: string,
 *   secure_boot: bool,
 *   graphic_console: bool,
 *   memballoon: bool
 * }
 */
function libvirtOptionsFromRequest(array $post, string $hostname): array
{
    return normalizeLibvirtOptions([
        'name' => $post['libvirt_name'] ?? '',
        'vcpu' => $post['libvirt_vcpu'] ?? 1,
        'memory' => $post['libvirt_memory'] ?? 2048,
        'bridge' => $post['libvirt_bridge'] ?? 'br0',
        'mac' => $post['libvirt_mac'] ?? '',
        'disk_path' => $post['libvirt_disk_path'] ?? '',
        'secure_boot' => !empty($post['libvirt_secure_boot']),
        'graphic_console' => !empty($post['libvirt_graphic_console']),
        'memballoon' => !empty($post['libvirt_memballoon']),
    ], $hostname);
}

/**
 * Sous-ensemble persisté dans build_options.json (pas d'UUID : il est figé au build).
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function libvirtOptionsForStorage(array $options): array
{
    return [
        'name' => $options['name'],
        'vcpu' => $options['vcpu'],
        'memory' => $options['memory'],
        'bridge' => $options['bridge'],
        'mac' => $options['mac'],
        'disk_path' => $options['disk_path'],
        'secure_boot' => $options['secure_boot'],
        'graphic_console' => $options['graphic_console'],
        'memballoon' => $options['memballoon'],
    ];
}

/**
 * @param array<string, mixed> $config
 * @return array{
 *   name: string,
 *   uuid: string,
 *   vcpu: int,
 *   memory: int,
 *   bridge: string,
 *   mac: string,
 *   disk_path: string,
 *   secure_boot: bool,
 *   graphic_console: bool,
 *   memballoon: bool
 * }
 */
function getLibvirtOptionsFromConfig(array $config, string $buildId): array
{
    $hostname = trim((string) ($config['hostname'] ?? 'seapath-host'));
    $saved = $config['libvirt'] ?? [];
    if (!is_array($saved)) {
        $saved = [];
    }
    return normalizeLibvirtOptions($saved, $hostname, $buildId);
}

function qcow2DownloadFilename(array $libvirt, string $buildId): string
{
    $base = basename((string) ($libvirt['disk_path'] ?? ''));
    if (preg_match('/^[A-Za-z0-9._-]+\.qcow2$/', $base)) {
        return $base;
    }
    $name = (string) ($libvirt['name'] ?? '');
    if (isValidLibvirtDomainName($name)) {
        return $name . '.qcow2';
    }
    return 'debian-' . $buildId . '.qcow2';
}

function libvirtXmlDownloadFilename(array $libvirt, string $buildId): string
{
    $name = (string) ($libvirt['name'] ?? '');
    if (isValidLibvirtDomainName($name)) {
        return $name . '.xml';
    }
    return 'debian-' . $buildId . '.xml';
}

function xmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * XML de domaine libvirt prêt à l'emploi, calqué sur guest.xml.j2 (profil non-RT).
 *
 * @param array<string, mixed> $options
 */
function generateLibvirtDomainXml(array $options): string
{
    $name = xmlEscape((string) $options['name']);
    $uuid = xmlEscape((string) $options['uuid']);
    $vcpu = (int) $options['vcpu'];
    $memory = (int) $options['memory'];
    $diskPath = xmlEscape((string) $options['disk_path']);
    $bridge = xmlEscape((string) $options['bridge']);
    $mac = xmlEscape((string) $options['mac']);
    $secureBoot = !empty($options['secure_boot']) ? 'yes' : 'no';

    $graphics = '';
    if (!empty($options['graphic_console'])) {
        $graphics = <<<'XML'
        <graphics type="vnc" port="-1" autoport="yes" listen="127.0.0.1">
            <listen type="address" address="127.0.0.1"/>
        </graphics>
        <video>
            <model type="virtio" heads="1" primary="yes"/>
        </video>
        <input type="tablet" bus="usb"/>

XML;
    }

    if (!empty($options['memballoon'])) {
        $balloon = <<<'XML'
        <memballoon model="virtio">
          <stats period="5" />
        </memballoon>
XML;
    } else {
        $balloon = '        <memballoon model="none" />';
    }

    return <<<XML
<!-- Generated by ISOBuilder for SEAPATH -->
<domain type="kvm">
    <name>{$name}</name>
    <uuid>{$uuid}</uuid>
    <description>SEAPATH guest</description>
    <vcpu placement="static">{$vcpu}</vcpu>
    <memory unit="MiB">{$memory}</memory>
    <currentMemory unit="MiB">{$memory}</currentMemory>
    <os firmware="efi">
        <type arch="x86_64" machine="q35">hvm</type>
        <boot dev="hd" />
        <bootmenu enable="no" />
        <bios useserial="yes" rebootTimeout="0" />
        <smbios mode="emulate" />
        <firmware>
            <feature enabled="{$secureBoot}" name="secure-boot"/>
        </firmware>
    </os>
    <features>
        <acpi />
        <apic />
        <vmport state="off" />
    </features>
    <cpu mode="host-model" check="partial">
        <model fallback="allow" />
    </cpu>
    <clock offset="utc">
        <timer name="rtc" tickpolicy="catchup" />
        <timer name="pit" tickpolicy="delay" />
        <timer name="hpet" present="no" />
    </clock>
    <on_poweroff>destroy</on_poweroff>
    <on_reboot>restart</on_reboot>
    <on_crash>destroy</on_crash>
    <pm>
        <suspend-to-mem enabled="no" />
        <suspend-to-disk enabled="no" />
    </pm>
    <devices>
        <emulator>/usr/bin/qemu-system-x86_64</emulator>
{$graphics}        <disk type="file" device="disk">
           <driver name="qemu" type="qcow2"/>
           <source file="{$diskPath}"/>
           <target dev="vda" bus="virtio"/>
        </disk>
        <interface type="bridge">
            <source bridge="{$bridge}"/>
            <mac address="{$mac}"/>
            <model type="virtio"/>
        </interface>
        <controller type="pci" index="0" model="pcie-root" />
        <serial type="pty">
            <target type="isa-serial" port="0">
                <model name="isa-serial" />
            </target>
        </serial>
        <console type="pty">
            <target type="serial" port="0" />
        </console>
{$balloon}
        <watchdog model="i6300esb" action="poweroff" />
    </devices>
</domain>

XML;
}

/**
 * @return array<string, mixed>
 */
function loadBuildConfig(string $buildPath, ?array $config = null): array
{
    if (is_array($config)) {
        return $config;
    }
    $configFile = $buildPath . '/config.json';
    if (is_readable($configFile)) {
        $decoded = json_decode((string) file_get_contents($configFile), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

/**
 * Décrit l'artefact produit par un build (fichier, extension, nom de téléchargement).
 *
 * @return array{target: string, file: string, download_filename: string, extension: string, has_xml: bool}
 */
function getBuildArtifactInfo(string $buildPath, ?array $config = null): array
{
    $config = loadBuildConfig($buildPath, $config);

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
        $hasXml = isset($config['libvirt']) && is_array($config['libvirt']);
        $downloadFilename = 'debian-' . $buildId . '.qcow2';
        if ($hasXml) {
            $downloadFilename = qcow2DownloadFilename(getLibvirtOptionsFromConfig($config, $buildId), $buildId);
        }
        return [
            'target' => 'qcow2',
            'file' => $qcow2File,
            'download_filename' => $downloadFilename,
            'extension' => 'qcow2',
            'has_xml' => $hasXml,
        ];
    }

    return [
        'target' => 'iso',
        'file' => $isoFile,
        'download_filename' => 'debian-' . $buildId . '.iso',
        'extension' => 'iso',
        'has_xml' => false,
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
