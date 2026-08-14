<?php
require_once 'config.php';
requireAuth();

header('Content-Type: application/json');

$base = realpath(getSessionUserCustomizationPath());

if ($base === false) {
    echo json_encode(['error' => t('editor.error.usercustomization_not_found')]);
    exit;
}

function normalizePath(string $path): string
{
    return ltrim(str_replace('\\', '/', $path), '/');
}

function resolvePath(string $base, string $relative): ?string
{
    $relative = normalizePath($relative);
    $target = $base . '/' . $relative;
    $real = realpath(dirname($target));

    // On permet aussi des chemins qui n'existent pas encore (pour création)
    if ($real === false) {
        $real = realpath($base);
        if ($real === false) {
            return null;
        }
        $target = $real . '/' . $relative;
    } else {
        $target = $real . '/' . basename($target);
    }

    if (strpos($target, $base) !== 0) {
        return null; // tentative d'évasion
    }
    return $target;
}

function isFileExecutable(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }
    return (fileperms($path) & 0111) !== 0;
}

function setFileExecutable(string $path, bool $executable): bool
{
    if (!is_file($path)) {
        return false;
    }
    $perms = fileperms($path) & 0777;
    if ($executable) {
        $perms |= 0111;
    } else {
        $perms &= ~0111;
    }
    return chmod($path, $perms);
}

function listTree(string $dir): array
{
    $children = [];
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        // Ignorer les fichiers .gitkeep
        if ($item === '.gitkeep') continue;
        $full = $dir . '/' . $item;
        if (is_dir($full)) {
            $children[] = [
                'name' => $item,
                'type' => 'dir',
                'children' => listTree($full)
            ];
        } else {
            $children[] = [
                'name' => $item,
                'type' => 'file'
            ];
        }
    }
    return $children;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'list') {
        echo json_encode([
            'name' => 'usercustomization',
            'type' => 'dir',
            'children' => listTree($base)
        ]);
        exit;
    }

    if ($action === 'list_srv') {
        // Lister srv_fai_config/ en lecture seule
        $repoPath = getSessionRepoPath();
        $srvFaiConfigPath = $repoPath . '/srv_fai_config';
        if (!is_dir($srvFaiConfigPath)) {
            echo json_encode([
                'name' => 'srv_fai_config',
                'type' => 'dir',
                'children' => []
            ]);
            exit;
        }
        echo json_encode([
            'name' => 'srv_fai_config',
            'type' => 'dir',
            'children' => listTree($srvFaiConfigPath)
        ]);
        exit;
    }

    if ($action === 'get') {
        $rel = $_GET['path'] ?? '';
        $target = resolvePath($base, $rel);
        if (!$target || !is_file($target)) {
            echo json_encode(['error' => t('editor.error.file_not_found')]);
            exit;
        }
        echo json_encode([
            'content' => file_get_contents($target),
            'executable' => isFileExecutable($target)
        ]);
        exit;
    }

    if ($action === 'get_srv') {
        // Lire un fichier de srv_fai_config/ en lecture seule
        $rel = $_GET['path'] ?? '';
        $repoPath = getSessionRepoPath();
        $srvFaiConfigBase = $repoPath . '/srv_fai_config';
        if (!is_dir($srvFaiConfigBase)) {
            echo json_encode(['error' => t('editor.error.srv_not_found')]);
            exit;
        }
        $relative = normalizePath($rel);
        $target = $srvFaiConfigBase . '/' . $relative;
        $real = realpath($target);
        if ($real === false || !is_file($real)) {
            echo json_encode(['error' => t('editor.error.file_not_found')]);
            exit;
        }
        // Vérification de sécurité : s'assurer que le fichier est bien dans srv_fai_config
        $realBase = realpath($srvFaiConfigBase);
        if ($realBase === false || strpos($real, $realBase) !== 0) {
            echo json_encode(['error' => t('editor.error.invalid_path')]);
            exit;
        }
        echo json_encode([
            'content' => file_get_contents($real),
            'readonly' => true
        ]);
        exit;
    }

    echo json_encode(['error' => t('editor.error.invalid_action')]);
    exit;
}

// POST actions: save, delete, create
$action = $_POST['action'] ?? '';

if ($action === 'save') {
    $rel = $_POST['path'] ?? '';
    $content = $_POST['content'] ?? '';
    $target = resolvePath($base, $rel);
    if (!$target) {
        echo json_encode(['error' => t('editor.error.invalid_path')]);
        exit;
    }
    $dir = dirname($target);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    // Normaliser les fins de ligne pour Linux (supprimer les \r)
    // D'abord remplacer CRLF par LF, puis remplacer les CR restants par LF
    $content = str_replace("\r\n", "\n", $content);
    $content = str_replace("\r", "\n", $content);
    // S'assurer qu'il y a toujours un EOL final (norme Unix)
    if ($content !== '' && substr($content, -1) !== "\n") {
        $content .= "\n";
    }
    file_put_contents($target, $content);
    if (isset($_POST['executable'])) {
        setFileExecutable($target, $_POST['executable'] === '1' || $_POST['executable'] === 'true');
    }
    echo json_encode(['ok' => true, 'executable' => isFileExecutable($target)]);
    exit;
}

if ($action === 'delete') {
    $rel = $_POST['path'] ?? '';
    $target = resolvePath($base, $rel);
    if (!$target || !file_exists($target)) {
        echo json_encode(['error' => t('editor.error.path_not_found')]);
        exit;
    }
    if (is_dir($target)) {
        // suppression récursive simple
        $it = new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($target);
    } else {
        unlink($target);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'create') {
    $rel = $_POST['path'] ?? '';
    $type = $_POST['type'] ?? 'file';
    $target = resolvePath($base, $rel);
    if (!$target) {
        echo json_encode(['error' => t('editor.error.invalid_path')]);
        exit;
    }
    if ($type === 'dir') {
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }
    } else {
        $dir = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!file_exists($target)) {
            file_put_contents($target, '');
        }
        if (isset($_POST['executable']) && ($_POST['executable'] === '1' || $_POST['executable'] === 'true')) {
            setFileExecutable($target, true);
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'chmod') {
    $rel = $_POST['path'] ?? '';
    $executable = isset($_POST['executable']) && ($_POST['executable'] === '1' || $_POST['executable'] === 'true');
    $target = resolvePath($base, $rel);
    if (!$target || !is_file($target)) {
        echo json_encode(['error' => t('editor.error.file_not_found')]);
        exit;
    }
    if (!setFileExecutable($target, $executable)) {
        echo json_encode(['error' => t('editor.error.chmod_failed')]);
        exit;
    }
    echo json_encode(['ok' => true, 'executable' => isFileExecutable($target)]);
    exit;
}

echo json_encode(['error' => t('editor.error.invalid_action')]);
exit;

