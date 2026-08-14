<?php
require_once 'config.php';
require_once 'auth.php'; // Pour avoir accès à deleteDirectory()
requireAuth();

const ISOBUILDER_PERMISSIONS_MANIFEST = '.isobuilder_permissions.json';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

if (!isset($_FILES['zipfile']) || $_FILES['zipfile']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'Erreur lors de l\'upload du fichier']);
    exit;
}

$uploadedFile = $_FILES['zipfile']['tmp_name'];
$fileName = $_FILES['zipfile']['name'];

// Vérifier que c'est bien un fichier zip
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $uploadedFile);
finfo_close($finfo);

if ($mimeType !== 'application/zip' && $mimeType !== 'application/x-zip-compressed') {
    // Vérifier aussi par extension
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        echo json_encode(['error' => 'Le fichier doit être un fichier ZIP']);
        exit;
    }
}

$sessionUserCustomizationPath = getSessionUserCustomizationPath();

// Créer un backup du dossier actuel (optionnel, mais recommandé)
$backupPath = $sessionUserCustomizationPath . '_backup_' . time();
if (is_dir($sessionUserCustomizationPath)) {
    if (!is_dir($backupPath)) {
        mkdir($backupPath, 0755, true);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sessionUserCustomizationPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $dest = $backupPath . '/' . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($dest)) {
                mkdir($dest, 0755, true);
            }
        } else {
            copy($item, $dest);
        }
    }
}

// Supprimer complètement le dossier usercustomization
if (is_dir($sessionUserCustomizationPath)) {
    deleteDirectory($sessionUserCustomizationPath);
}

// Extraire le fichier zip dans un répertoire temporaire
$tempExtractDir = sys_get_temp_dir() . '/usercustomization_import_' . session_id() . '_' . time();
if (!is_dir($tempExtractDir)) {
    mkdir($tempExtractDir, 0755, true);
}

$zip = new ZipArchive();
if ($zip->open($uploadedFile) !== TRUE) {
    echo json_encode(['error' => 'Impossible d\'ouvrir le fichier ZIP']);
    exit;
}

// Manifeste explicite des permissions (source la plus fiable)
$manifestPermissions = [];
$manifestContent = $zip->getFromName(ISOBUILDER_PERMISSIONS_MANIFEST);
if ($manifestContent !== false) {
    $decoded = json_decode($manifestContent, true);
    if (is_array($decoded)) {
        foreach ($decoded as $path => $perms) {
            $manifestPermissions[str_replace('\\', '/', $path)] = (int) $perms;
        }
    }
}

// Attributs ZIP Unix en secours
$zipPermissions = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    if (!$stat) {
        continue;
    }
    $entryName = str_replace('\\', '/', $stat['name']);
    if ($entryName === ISOBUILDER_PERMISSIONS_MANIFEST || substr($entryName, -1) === '/') {
        continue;
    }
    $perms = ($stat['external_attributes'] >> 16) & 0777;
    if ($perms > 0) {
        $zipPermissions[$entryName] = $perms;
    }
}

$zip->extractTo($tempExtractDir);
$zip->close();

// Trouver où se trouve le contenu usercustomization
if (is_dir($tempExtractDir . '/usercustomization')) {
    $sourcePath = $tempExtractDir . '/usercustomization';
} else {
    $sourcePath = $tempExtractDir;
}

if (!is_dir($sessionUserCustomizationPath)) {
    mkdir($sessionUserCustomizationPath, 0755, true);
}

if (!is_dir($sourcePath)) {
    deleteDirectory($tempExtractDir);
    echo json_encode(['error' => 'Le fichier ZIP ne contient pas de contenu valide']);
    exit;
}

function resolveImportedFilePermissions(string $relativePath, array $manifestPermissions, array $zipPermissions, string $sourceFile): ?int
{
    $relativePath = str_replace('\\', '/', $relativePath);

    if (isset($manifestPermissions[$relativePath])) {
        return $manifestPermissions[$relativePath];
    }

    $zipEntryCandidates = [
        'usercustomization/' . $relativePath,
        $relativePath,
    ];
    foreach ($zipEntryCandidates as $zipEntry) {
        if (isset($zipPermissions[$zipEntry])) {
            return $zipPermissions[$zipEntry];
        }
    }

    if (is_file($sourceFile)) {
        $srcPerms = fileperms($sourceFile) & 0777;
        if (($srcPerms & 0111) !== 0) {
            return $srcPerms;
        }
    }

    return null;
}

$copiedFiles = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourcePath, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    $subPathName = str_replace('\\', '/', $iterator->getSubPathName());
    $destPath = $sessionUserCustomizationPath . '/' . $subPathName;

    if ($item->isDir()) {
        if (!is_dir($destPath)) {
            if (!mkdir($destPath, 0755, true)) {
                deleteDirectory($tempExtractDir);
                echo json_encode(['error' => 'Impossible de créer le répertoire: ' . $destPath]);
                exit;
            }
        }
        continue;
    }

    $destDir = dirname($destPath);
    if (!is_dir($destDir)) {
        if (!mkdir($destDir, 0755, true)) {
            deleteDirectory($tempExtractDir);
            echo json_encode(['error' => 'Impossible de créer le répertoire parent: ' . $destDir]);
            exit;
        }
    }

    if (!copy($item->getPathname(), $destPath)) {
        deleteDirectory($tempExtractDir);
        echo json_encode(['error' => 'Impossible de copier le fichier: ' . $item->getPathname()]);
        exit;
    }

    $perms = resolveImportedFilePermissions(
        $subPathName,
        $manifestPermissions,
        $zipPermissions,
        $item->getPathname()
    );
    if ($perms !== null) {
        chmod($destPath, $perms);
    }

    $copiedFiles++;
}

deleteDirectory($tempExtractDir);

echo json_encode([
    'success' => true,
    'message' => 'Import réussi ! Le dossier usercustomization a été remplacé.',
    'backup' => basename($backupPath)
]);
exit;
