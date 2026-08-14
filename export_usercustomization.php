<?php
require_once 'config.php';
requireAuth();

const ISOBUILDER_PERMISSIONS_MANIFEST = '.isobuilder_permissions.json';

$sessionUserCustomizationPath = getSessionUserCustomizationPath();

if (!is_dir($sessionUserCustomizationPath)) {
    die('Le dossier usercustomization n\'existe pas');
}

// Créer un fichier zip temporaire
$zipFile = sys_get_temp_dir() . '/usercustomization_' . session_id() . '_' . time() . '.zip';
$zip = new ZipArchive();

if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    die('Impossible de créer le fichier zip');
}

$filePermissions = [];

// Fonction récursive pour ajouter les fichiers au zip
function addDirectoryToZip(ZipArchive $zip, string $dir, string $basePath, array &$filePermissions): void
{
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || $file === '.gitkeep') {
            continue;
        }

        $filePath = $dir . '/' . $file;
        $zipPath = $basePath ? $basePath . '/' . $file : $file;
        $zipPath = str_replace('\\', '/', $zipPath);

        if (is_dir($filePath)) {
            $zip->addEmptyDir($zipPath);
            addDirectoryToZip($zip, $filePath, $zipPath, $filePermissions);
        } else {
            $mode = fileperms($filePath);
            $perms = $mode & 0777;
            $relativePath = $zipPath;
            if (strpos($relativePath, 'usercustomization/') === 0) {
                $relativePath = substr($relativePath, strlen('usercustomization/'));
            }
            $filePermissions[$relativePath] = $perms;

            $zip->addFromString($zipPath, file_get_contents($filePath));
            $zip->setExternalAttributesName($zipPath, ZipArchive::OPSYS_UNIX, ($mode & 0xFFFF) << 16);
        }
    }
}

// Ajouter tous les fichiers du dossier usercustomization avec le préfixe "usercustomization/"
addDirectoryToZip($zip, $sessionUserCustomizationPath, 'usercustomization', $filePermissions);

// Manifeste explicite des permissions (fiable même si les attributs ZIP sont perdus)
$zip->addFromString(
    ISOBUILDER_PERMISSIONS_MANIFEST,
    json_encode($filePermissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

$zip->close();

// Envoyer le fichier zip
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="usercustomization_' . date('Y-m-d_His') . '.zip"');
header('Content-Length: ' . filesize($zipFile));
readfile($zipFile);

// Supprimer le fichier temporaire
unlink($zipFile);
exit;
