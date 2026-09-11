<?php
require_once 'config.php';
requireAuth();

$currentLang = getLanguage();

$buildId = $_GET['id'] ?? '';
if (empty($buildId)) {
    die(t('logs.build_id_missing'));
}

$buildPath = BUILDS_PATH . '/' . $buildId;
if (!is_dir($buildPath)) {
    die(t('logs.build_not_found'));
}

$logFile = $buildPath . '/logs.txt';
$statusFile = $buildPath . '/status.txt';
$artifact = getBuildArtifactInfo($buildPath);
$downloadLabel = $artifact['target'] === 'qcow2'
    ? t('logs.download_qcow2')
    : t('logs.download_iso');

$repoRef = '';
$configFile = $buildPath . '/config.json';
if (is_readable($configFile)) {
    $config = json_decode((string) file_get_contents($configFile), true);
    if (is_array($config) && !empty($config['repo_ref'])) {
        $repoRef = (string) $config['repo_ref'];
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(t('logs.title', ['buildId' => $buildId])) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: Arial, sans-serif; 
            margin: 0;
            padding: 12px; 
            background: #1e1e1e; 
            color: #d4d4d4; 
            font-size: 0.9em;
        }
        .header { 
            background: #252526; 
            padding: 12px; 
            margin-bottom: 12px; 
            border-radius: 4px;
        }
        .header h1 {
            margin: 0;
            font-size: 1.2em;
        }
        .header a {
            color: #4fc3f7;
            text-decoration: none;
            font-size: 0.85em;
        }
        #logs { 
            background: #1e1e1e; 
            border: 1px solid #3e3e42; 
            padding: 12px; 
            font-family: monospace; 
            white-space: pre-wrap; 
            height: 400px; 
            overflow-y: auto; 
            border-radius: 4px;
            font-size: 0.85em;
            line-height: 1.4;
        }
        #status { 
            padding: 8px 10px; 
            margin: 8px 0; 
            font-weight: bold; 
            border-radius: 4px;
            font-size: 0.85em;
        }
        .building { background: #ffc107; color: black; }
        .completed { background: #28a745; color: white; }
        .failed { background: #dc3545; color: white; }
        #download {
            margin-top: 12px;
            padding: 10px;
            background: #252526;
            border-radius: 4px;
        }
        #download h2 {
            margin: 0 0 10px 0;
            font-size: 1em;
        }
        #download a {
            padding: 8px 16px;
            background: #28a745;
            color: white;
            text-decoration: none;
            display: inline-block;
            border-radius: 3px;
            font-size: 0.85em;
            margin-right: 8px;
        }
        #download a.xml {
            background: #17a2b8;
        }
        a { color: #4fc3f7; }
    </style>
</head>
<body>
    <div class="header">
        <h1><?= htmlspecialchars(t('logs.build', ['buildId' => $buildId])) ?></h1>
        <?php if ($repoRef !== ''): ?>
        <p style="margin: 6px 0 0 0; font-size: 0.85em; color: #9cdcfe;"><?= htmlspecialchars(t('logs.repo_ref', ['ref' => $repoRef])) ?></p>
        <?php endif; ?>
        <a href="dashboard.php"><?= htmlspecialchars(t('logs.back')) ?></a>
    </div>
    
    <div id="status" class="building"><?= htmlspecialchars(t('logs.status.building')) ?></div>
    <div id="logs"><?= htmlspecialchars(t('logs.loading')) ?></div>
    
    <div id="download" style="display: none; margin-top: 20px;">
        <h2><?= htmlspecialchars(t('logs.download')) ?></h2>
        <a href="download.php?id=<?= urlencode($buildId) ?>" style="padding: 10px 20px; background: #28a745; color: white; text-decoration: none; display: inline-block;">
            <?= htmlspecialchars($downloadLabel) ?>
        </a>
        <?php if (!empty($artifact['has_xml'])): ?>
        <a class="xml" href="download.php?id=<?= urlencode($buildId) ?>&type=xml" style="padding: 10px 20px; background: #17a2b8; color: white; text-decoration: none; display: inline-block;">
            <?= htmlspecialchars(t('logs.download_xml')) ?>
        </a>
        <?php endif; ?>
    </div>
    
    <script>
        const buildId = <?= json_encode($buildId) ?>;
        let lastSize = 0;
        
        // Traductions JavaScript
        const translations = {
            'status.building': <?= json_encode(t('logs.status.building')) ?>,
            'status.completed': <?= json_encode(t('logs.status.completed')) ?>,
            'status.failed': <?= json_encode(t('logs.status.failed')) ?>,
            'status.waiting': <?= json_encode(t('logs.status.waiting')) ?>
        };
        
        function getStatusLabel(status) {
            const key = 'status.' + status;
            return translations[key] || 'Status: ' + status;
        }
        
        function updateLogs() {
            fetch(`get_logs.php?id=${encodeURIComponent(buildId)}&from=${lastSize}`)
                .then(r => r.json())
                .then(data => {
                    if (data.content) {
                        const logsDiv = document.getElementById('logs');
                        logsDiv.textContent += data.content;
                        logsDiv.scrollTop = logsDiv.scrollHeight;
                        lastSize = data.size;
                    }
                    
                    // Mettre à jour le status
                    const statusDiv = document.getElementById('status');
                    statusDiv.textContent = getStatusLabel(data.status);
                    statusDiv.className = data.status;
                    
                    // Afficher le bouton de téléchargement si terminé
                    if (data.status === 'completed') {
                        document.getElementById('download').style.display = 'block';
                        clearInterval(updateInterval);
                    } else if (data.status === 'failed') {
                        clearInterval(updateInterval);
                    }
                });
        }
        
        updateLogs();
        const updateInterval = setInterval(updateLogs, 2000);
    </script>
</body>
</html>
