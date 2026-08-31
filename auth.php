<?php
require_once 'config.php';

/**
 * Vérifie un mot de passe contre un hash htpasswd
 * Supporte les formats: bcrypt ($2y$), Apache MD5 ($apr1$), SHA-1 ({SHA})
 * Utilise htpasswd en ligne de commande si disponible, sinon implémentation PHP
 */
function verifyHtpasswdPassword($password, $hash) {
    // Format bcrypt (htpasswd -B ou password_hash) - le plus commun et sécurisé
    if (preg_match('/^\$2[ay]\$/', $hash)) {
        return password_verify($password, $hash);
    }
    
    // Format Apache MD5 (htpasswd -m) - utiliser htpasswd command si disponible
    if (preg_match('/^\$apr1\$/', $hash)) {
        // Essayer d'utiliser la commande htpasswd (plus fiable)
        $testFile = sys_get_temp_dir() . '/htpasswd_test_' . uniqid() . '.htpasswd';
        file_put_contents($testFile, "testuser:$hash\n");
        $passwordEscaped = escapeshellarg($password);
        $fileEscaped = escapeshellarg($testFile);
        
        // Vérifier avec htpasswd -v (si disponible)
        $output = [];
        $returnVar = 0;
        @exec("htpasswd -vb $fileEscaped testuser $passwordEscaped 2>&1", $output, $returnVar);
        @unlink($testFile);
        
        if ($returnVar === 0) {
            return true;
        }
        
        // Fallback: utiliser crypt() si disponible (supporte $apr1$ sur certains systèmes)
        if (function_exists('crypt')) {
            $result = crypt($password, $hash);
            return hash_equals($hash, $result);
        }
        
        // Dernier recours: implémentation PHP simplifiée (peut ne pas fonctionner parfaitement)
        return false; // Pour Apache MD5, on nécessite htpasswd ou crypt()
    }
    
    // Format SHA-1 (htpasswd -s)
    if (preg_match('/^{SHA}(.+)$/', $hash, $matches)) {
        $storedHash = $matches[1];
        $computedHash = base64_encode(sha1($password, true));
        return hash_equals($storedHash, $computedHash);
    }
    
    // Plain text (non recommandé, mais supporté pour compatibilité - désactivé par défaut pour sécurité)
    // Décommenter la ligne suivante si vous avez vraiment besoin de plain text
    // if (!preg_match('/^[{$].*\$/', $hash)) {
    //     return hash_equals($hash, $password);
    // }
    
    return false;
}

/**
 * Lit et parse le fichier htpasswd
 */
function readHtpasswdFile($filePath) {
    $users = [];
    
    if (!file_exists($filePath)) {
        error_log("Htpasswd file not found: $filePath");
        return $users;
    }
    
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // Ignorer les commentaires
        if (empty($line) || $line[0] === '#') {
            continue;
        }
        
        // Format: username:hash
        if (preg_match('/^([^:]+):(.+)$/', $line, $matches)) {
            $users[$matches[1]] = $matches[2];
        }
    }
    
    return $users;
}

function login($username, $password) {
    $authFile = '/opt/isobuilder/authlist.txt';
    
    // Vérifier que le fichier existe et est lisible
    if (!file_exists($authFile)) {
        error_log("Authentication file not found: $authFile. Please create it using 'htpasswd -B -c $authFile username'");
        return false;
    }
    
    if (!is_readable($authFile)) {
        error_log("Authentication file is not readable: $authFile. Check file permissions.");
        return false;
    }
    
    // Lire le fichier htpasswd
    $users = readHtpasswdFile($authFile);
    
    if (empty($users)) {
        error_log("No valid users found in auth file: $authFile. File may be empty or malformed.");
        return false;
    }
    
    // Vérifier que l'utilisateur existe
    if (!isset($users[$username])) {
        // Ne pas logger pour éviter les attaques par énumération, mais logger en mode debug
        return false;
    }
    
    // Vérifier les identifiants
    if (verifyHtpasswdPassword($password, $users[$username])) {
        $_SESSION['user_id'] = $username;
        $_SESSION['username'] = $username;
        
        // Au login, supprimer le workspace et recréer le dépôt pour avoir la dernière version
        $workspace = getSessionWorkspacePath();
        $repoPath = $workspace . '/build_debian_iso';
        
        // Supprimer le workspace au cas où (pour garantir qu'on part de zéro)
        if (is_dir($repoPath)) {
            deleteDirectory($repoPath);
        }
        
        // Le clone sera fait automatiquement par getSessionRepoPath() quand nécessaire
        // On ne l'appelle pas ici car on n'a pas besoin du repo immédiatement au login
        
        return true;
    }
    
    return false;
}

function logout() {
    if (!isset($_SESSION['username'])) {
        session_destroy();
        return;
    }
    
    $username = $_SESSION['username'];
    $sessionId = session_id();
    
    // 1. Tuer tous les builds en cours pour cet utilisateur
    if (is_dir(BUILDS_PATH)) {
        $buildDirs = glob(BUILDS_PATH . '/*', GLOB_ONLYDIR);
        foreach ($buildDirs as $buildDir) {
            $configFile = $buildDir . '/config.json';
            $pidFile = $buildDir . '/pid.txt';
            $statusFile = $buildDir . '/status.txt';
            
            // Vérifier si cette build appartient à l'utilisateur
            $isUserBuild = false;
            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true);
                if (isset($config['user']) && $config['user'] === $username) {
                    $isUserBuild = true;
                }
            }
            
            if ($isUserBuild) {
                // Lire le statut
                $status = 'unknown';
                if (file_exists($statusFile)) {
                    $status = trim(file_get_contents($statusFile));
                }
                
                $buildId = basename($buildDir);
                
                // Retirer de la file d'attente si la build est en attente
                if ($status === 'waiting') {
                    removeBuildFromQueue($buildId);
                }
                
                // Si la build est en cours, tuer le processus et libérer le mutex
                if ($status === 'building') {
                    // Tuer le processus si disponible
                    if (file_exists($pidFile)) {
                        $pid = trim(file_get_contents($pidFile));
                        if (!empty($pid) && is_numeric($pid)) {
                            // Tuer le processus et ses enfants
                            @exec("pkill -P {$pid} 2>/dev/null");
                            @exec("kill {$pid} 2>/dev/null");
                        }
                    }
                    
                    // Libérer le mutex si c'est cette build qui était en cours
                    if (file_exists(BUILD_MUTEX_FILE)) {
                        $mutexData = json_decode(file_get_contents(BUILD_MUTEX_FILE), true);
                        if ($mutexData && isset($mutexData['build_id']) && $mutexData['build_id'] === $buildId) {
                            releaseBuildMutex();
                        }
                    }
                }
                
                // Supprimer le répertoire de build
                deleteDirectory($buildDir);
            }
        }
    }
    
    // 2. Supprimer l'espace de travail de l'utilisateur (avant de détruire la session)
    $workspace = getSessionWorkspacePath();
    if (is_dir($workspace)) {
        deleteDirectory($workspace);
    }
    
    // 3. Détruire la session
    session_destroy();
}
?>
