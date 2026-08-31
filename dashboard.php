<?php
require_once 'config.php';
requireAuth();

// S'assure qu'un espace de travail + clone du dépôt existent pour cette session
getSessionRepoPath();

// Lire les valeurs existantes depuis USERCUSTOMIZATION.var si le fichier existe
$sessionUserCustomizationPath = getSessionUserCustomizationPath();
$varFile = $sessionUserCustomizationPath . '/class/USERCUSTOMIZATION.var';
$existingVars = [];

if (file_exists($varFile)) {
    $content = file_get_contents($varFile);
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === '#') continue;
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)=(.*)$/', $line, $matches)) {
            $existingVars[$matches[1]] = parseVarFileValue($matches[2]);
        }
    }
}

// Valeurs par défaut pour les champs du formulaire
// Accepter HOSTNAME en majuscule (nouveau format) ou hostname en minuscule (ancien format) pour rétrocompatibilité
$formHostname = $existingVars['HOSTNAME'] ?? $existingVars['hostname'] ?? 'seapath-host';
$formUSERPW = $existingVars['USERPW'] ?? '';
$formMyrootkey = $existingVars['myrootkey'] ?? '';
$formMyuserkey = $existingVars['myuserkey'] ?? '';
$formAnsiblekey = $existingVars['ansiblekey'] ?? '';
$formREMOTENIC = $existingVars['REMOTENIC'] ?? '';
$formREMOTEADDR = $existingVars['REMOTEADDR'] ?? '';
$formREMOTEGW = $existingVars['REMOTEGW'] ?? '';
$formREMOTEVLANID = $existingVars['REMOTEVLANID'] ?? '';

// Options supportées par build_iso.sh du dépôt cloné (branche/tag courant)
$buildIsoOptions = parseBuildIsoOptions();
$availablePackageClasses = $buildIsoOptions['package_classes'];
$availableMenuFlags = $buildIsoOptions['menu_flags'];
$qcow2Supported = repoSupportsQcow2();
$selectedRepoRef = getSelectedRepoRef();
$repoRefs = listSeapathRepoRefs();
if (!in_array($selectedRepoRef, $repoRefs['branches'], true)
    && !in_array($selectedRepoRef, $repoRefs['tags'], true)
) {
    $repoRefs['branches'][] = $selectedRepoRef;
}

// Lire les options de build depuis build_options.json
$buildOptionsFile = $sessionUserCustomizationPath . '/build_options.json';
$savedPackageClasses = ['SEAPATH_CLUSTER']; // Valeur par défaut
$savedMenuItems = [['french', 'cluster']]; // Valeur par défaut
$savedTarget = 'iso';
$savedVmDiskSize = '10G';
$savedCloudInit = false;

if (file_exists($buildOptionsFile)) {
    $buildOptionsJson = file_get_contents($buildOptionsFile);
    $buildOptions = json_decode($buildOptionsJson, true);
    if (is_array($buildOptions)) {
        if (isset($buildOptions['package_classes']) && is_array($buildOptions['package_classes']) && !empty($buildOptions['package_classes'])) {
            $savedPackageClasses = $buildOptions['package_classes'];
        }
        if (isset($buildOptions['menu_items']) && is_array($buildOptions['menu_items']) && !empty($buildOptions['menu_items'])) {
            $savedMenuItems = $buildOptions['menu_items'];
        }
        $savedTarget = normalizeBuildTarget($buildOptions['target'] ?? null);
        if (!empty($buildOptions['vmdisksize']) && isValidVmDiskSize((string) $buildOptions['vmdisksize'])) {
            $savedVmDiskSize = $buildOptions['vmdisksize'];
        }
        $savedCloudInit = !empty($buildOptions['cloud_init']);
    }
}

if (!$qcow2Supported) {
    $savedTarget = 'iso';
}

// Ne garder que les options encore supportées par la branche clonée
$savedPackageClasses = array_values(array_intersect($savedPackageClasses, $availablePackageClasses));
if (empty($savedPackageClasses) && !empty($availablePackageClasses)) {
    $savedPackageClasses = [$availablePackageClasses[0]];
}
$savedMenuItems = array_map(
    static fn(array $list): array => array_values(array_intersect($list, $availableMenuFlags)),
    $savedMenuItems
);
if (empty($savedMenuItems)) {
    $savedMenuItems = [[]];
}

$currentLang = getLanguage();
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars(t('dashboard.title')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: Arial, sans-serif; 
            margin: 0;
            padding: 12px;
            background: #f5f5f5;
            min-height: 100vh;
            font-size: 0.9em;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 12px;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
            flex-wrap: wrap;
            gap: 12px;
        }
        .header h1 {
            margin: 0;
            font-size: 1.2em;
            white-space: nowrap;
        }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            flex: 1;
            justify-content: flex-end;
        }
        .logout {
            padding: 5px 10px;
            background: #dc3545;
            color: white;
            text-decoration: none;
            border-radius: 3px;
            white-space: nowrap;
            font-size: 0.75em;
        }
        .header-customization {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8em;
        }
        .header-customization a {
            text-decoration: none;
            color: #007bff;
            padding: 4px 8px;
        }
        .header-customization .btn {
            padding: 5px 12px;
            font-size: 0.8em;
            color: white;
        }
        .header-customization form {
            display: inline-block;
            margin: 0;
        }
        .content-wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        @media (max-width: 1024px) {
            .content-wrapper {
                grid-template-columns: 1fr;
            }
        }
        .form-section {
            background: #fafafa;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #e0e0e0;
        }
        .form-section h2, .form-section h3 {
            margin-top: 0;
            margin-bottom: 8px;
            font-size: 0.9em;
            color: #333;
        }
        .form-group { 
            margin: 8px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-group label { 
            display: inline-block;
            font-weight: bold; 
            margin: 0;
            font-size: 0.8em;
            min-width: 120px;
            flex-shrink: 0;
        }
        .form-group input[type="text"], 
        .form-group input[type="password"], 
        .form-group select, 
        .form-group textarea { 
            flex: 1;
            padding: 6px; 
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 0.8em;
        }
        input[type="text"], input[type="password"], select, textarea { 
            width: 100%; 
            padding: 6px; 
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 0.8em;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
        }
        button, .btn {
            padding: 8px 16px; 
            background: #28a745; 
            color: white; 
            border: none; 
            cursor: pointer; 
            border-radius: 3px;
            font-size: 0.8em;
            white-space: nowrap;
        }
        button:hover, .btn:hover {
            opacity: 0.9;
        }
        .btn-secondary {
            background: #17a2b8;
        }
        .btn-danger {
            background: #dc3545;
        }
        textarea { 
            height: 56px; 
            font-family: monospace; 
            resize: vertical;
        }
        .menu-item-list { 
            border: 1px solid #ddd; 
            padding: 10px; 
            margin: 8px 0; 
            background: #f9f9f9; 
            border-radius: 3px; 
        }
        .menu-item-list-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 8px; 
        }
        .menu-item-list-title { 
            font-weight: bold; 
            font-size: 0.8em;
        }
        .menu-item-checkboxes { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 10px; 
        }
        .menu-item-checkboxes label { 
            margin: 0; 
            font-weight: normal;
            font-size: 0.75em;
        }
        .repo-ref-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        .repo-ref-row label {
            min-width: 120px;
            font-weight: bold;
            font-size: 0.8em;
            margin: 0;
        }
        .repo-ref-row select {
            flex: 1;
            min-width: 160px;
        }
        .repo-ref-help {
            margin: 0 0 12px 0;
            font-size: 0.75em;
            color: #666;
        }
        .target-toggle {
            display: flex;
            width: fit-content;
            margin: 0 0 12px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            overflow: hidden;
        }
        .target-toggle label {
            margin: 0;
            padding: 6px 16px;
            cursor: pointer;
            font-size: 0.8em;
            font-weight: bold;
            background: #f9f9f9;
            min-width: auto;
        }
        .target-toggle input {
            display: none;
        }
        .target-toggle label:has(input:checked) {
            background: #007bff;
            color: white;
        }
        .package-list { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 6px; 
        }
        .package-item { 
            display: flex; 
            align-items: center; 
            gap: 6px; 
            padding: 6px 10px; 
            border: 1px solid #ddd; 
            border-radius: 3px; 
            background: #f9f9f9; 
            font-weight: normal; 
            cursor: pointer; 
            transition: all 0.2s;
            font-size: 0.8em;
        }
        .package-item:hover { 
            background: #eef5ff; 
            border-color: #b3c7ff; 
        }
        .package-item input { 
            width: auto; 
            margin: 0; 
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid #eee;
        }
        .builds-section {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 2px solid #eee;
            overflow-x: auto;
        }
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            border-radius: 5px;
            width: 90%;
            max-width: 400px;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .modal-header h3 {
            margin: 0;
        }
        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover,
        .close:focus {
            color: #000;
        }
        .modal-body {
            margin-bottom: 15px;
        }
        .modal-body input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 0.9em;
        }
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .builds-section h2 {
            margin-top: 0;
            margin-bottom: 8px;
            font-size: 0.95em;
        }
        .builds-section table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            font-size: 0.85em;
        }
        .builds-section th,
        .builds-section td {
            padding: 6px 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .builds-section th {
            background: #f8f9fa;
            font-weight: bold;
            font-size: 0.8em;
            text-transform: uppercase;
            color: #666;
        }
        .builds-section td:first-child {
            font-family: monospace;
            font-size: 0.8em;
        }
        .builds-section td:nth-child(5) {
            font-size: 0.8em;
            color: #666;
            white-space: nowrap;
        }
        .builds-section td:last-child {
            font-size: 0.8em;
        }
        .builds-section td:last-child a {
            white-space: nowrap;
            margin-right: 6px;
        }
        .status-building { color: #ffc107; font-weight: bold; font-size: 0.9em; }
        .status-completed { color: #28a745; font-weight: bold; font-size: 0.9em; }
        .status-failed { color: #dc3545; font-weight: bold; font-size: 0.9em; }
        .status-waiting { color: #17a2b8; font-weight: bold; font-size: 0.9em; }
        .status-unknown { color: #6c757d; font-size: 0.9em; }
        .network-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .network-grid .form-group {
            margin: 0;
        }
        @media (max-width: 768px) {
            .network-grid {
                grid-template-columns: 1fr;
            }
        }
        #importMessage {
            font-size: 0.7em;
            margin-left: 4px;
            white-space: nowrap;
        }
        #importMessage span {
            display: inline-block;
        }
        .alert {
            padding: 8px 10px;
            margin: 8px 0;
            border-radius: 3px;
            font-size: 0.75em;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .lang-selector {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .lang-selector a {
            text-decoration: none;
            color: #007bff;
            padding: 2px 6px;
        }
        .lang-selector a.active {
            font-weight: bold;
        }
        @media (max-width: 768px) {
            body {
                padding: 8px;
            }
            .container {
                padding: 10px;
            }
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .header-actions {
                width: 100%;
                justify-content: space-between;
                flex-wrap: wrap;
            }
            .header-customization {
                flex-wrap: wrap;
            }
            .package-list {
                flex-direction: column;
            }
            .action-buttons {
                flex-direction: column;
            }
            .action-buttons button, .action-buttons .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?= htmlspecialchars(t('dashboard.title')) ?></h1>
            <div class="header-actions">
                <div class="lang-selector">
                    <a href="?lang=fr" class="<?= $currentLang === 'fr' ? 'active' : '' ?>">FR</a>
                    <span>|</span>
                    <a href="?lang=en" class="<?= $currentLang === 'en' ? 'active' : '' ?>">EN</a>
                </div>
                <div class="header-customization">
                    <a href="editor.php" target="_blank">✏️ <?= htmlspecialchars(t('dashboard.editor')) ?></a>
                    <a href="export_usercustomization.php" class="btn btn-secondary">📥 <?= htmlspecialchars(t('dashboard.export')) ?></a>
                    <form id="importForm" onsubmit="importUsercustomization(event); return false;">
                        <input type="file" name="zipfile" accept=".zip" required style="display: none;" id="zipFileInput" onchange="importUsercustomization(event)">
                        <button type="button" onclick="document.getElementById('zipFileInput').click()" class="btn">📤 <?= htmlspecialchars(t('dashboard.import')) ?></button>
                    </form>
                    <span id="importMessage"></span>
                </div>
                <a href="index.php?logout=1" class="logout"><?= htmlspecialchars(t('dashboard.logout')) ?></a>
            </div>
        </div>
        
        <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success">
                <?= t('dashboard.saved') ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['deleted'])): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars(t('dashboard.deleted')) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['queued'])): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars(t('dashboard.queued')) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['ref_switched'])): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars(t('dashboard.repo_ref.switched')) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['ref_error'])): ?>
            <div class="alert" style="background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;">
                <?= htmlspecialchars(t('dashboard.repo_ref.error')) ?>
            </div>
        <?php endif; ?>
    
        <form id="buildForm" method="POST" action="build.php">
            <div class="content-wrapper">
                <div class="form-section">
                    <h2><?= htmlspecialchars(t('dashboard.basic_config')) ?></h2>
                    <div class="form-group">
                        <label><?= htmlspecialchars(t('dashboard.hostname')) ?></label>
                        <input type="text" name="hostname" value="<?= htmlspecialchars($formHostname) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label><?= htmlspecialchars(t('dashboard.USERPW')) ?></label>
                        <div style="display: flex; gap: 8px; flex: 1;">
                            <input type="text" name="USERPW" id="userpwField" value="<?= htmlspecialchars($formUSERPW) ?>" placeholder="<?= htmlspecialchars(t('dashboard.USERPW.placeholder')) ?>" style="flex: 1;">
                            <button type="button" onclick="openPasswordHashModal()" class="btn btn-secondary" style="font-size: 0.75em; padding: 4px 12px; white-space: nowrap;">🔑 <?= htmlspecialchars(t('dashboard.hash_password')) ?></button>
                        </div>
                    </div>
                    
                    <h3><?= htmlspecialchars(t('dashboard.ssh_keys')) ?></h3>
                    <div class="form-group">
                        <label><?= htmlspecialchars(t('dashboard.myrootkey')) ?></label>
                        <input type="text" name="myrootkey" value="<?= htmlspecialchars($formMyrootkey) ?>" placeholder="<?= htmlspecialchars(t('dashboard.myrootkey.placeholder')) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label><?= htmlspecialchars(t('dashboard.myuserkey')) ?></label>
                        <input type="text" name="myuserkey" value="<?= htmlspecialchars($formMyuserkey) ?>" placeholder="<?= htmlspecialchars(t('dashboard.myuserkey.placeholder')) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label><?= htmlspecialchars(t('dashboard.ansiblekey')) ?></label>
                        <input type="text" name="ansiblekey" value="<?= htmlspecialchars($formAnsiblekey) ?>" placeholder="<?= htmlspecialchars(t('dashboard.ansiblekey.placeholder')) ?>">
                    </div>
                    
                    <h3><?= htmlspecialchars(t('dashboard.remote_network')) ?></h3>
                    <div class="network-grid">
                        <div class="form-group">
                            <label><?= htmlspecialchars(t('dashboard.REMOTENIC')) ?></label>
                            <input type="text" name="REMOTENIC" value="<?= htmlspecialchars($formREMOTENIC) ?>" placeholder="<?= htmlspecialchars(t('dashboard.REMOTENIC.placeholder')) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label><?= htmlspecialchars(t('dashboard.REMOTEADDR')) ?></label>
                            <input type="text" name="REMOTEADDR" value="<?= htmlspecialchars($formREMOTEADDR) ?>" placeholder="<?= htmlspecialchars(t('dashboard.REMOTEADDR.placeholder')) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label><?= htmlspecialchars(t('dashboard.REMOTEGW')) ?></label>
                            <input type="text" name="REMOTEGW" value="<?= htmlspecialchars($formREMOTEGW) ?>" placeholder="<?= htmlspecialchars(t('dashboard.REMOTEGW.placeholder')) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label><?= htmlspecialchars(t('dashboard.REMOTEVLANID')) ?></label>
                            <input type="text" name="REMOTEVLANID" value="<?= htmlspecialchars($formREMOTEVLANID) ?>" placeholder="<?= htmlspecialchars(t('dashboard.REMOTEVLANID.placeholder')) ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h2><?= htmlspecialchars(t('dashboard.build_options')) ?></h2>
                    <div class="repo-ref-row">
                        <label for="repoRefSelect"><?= htmlspecialchars(t('dashboard.repo_ref')) ?></label>
                        <select id="repoRefSelect">
                            <?php if (!empty($repoRefs['branches'])): ?>
                            <optgroup label="<?= htmlspecialchars(t('dashboard.repo_ref.branches')) ?>">
                                <?php foreach ($repoRefs['branches'] as $branchName): ?>
                                <option value="<?= htmlspecialchars($branchName) ?>" <?= $branchName === $selectedRepoRef ? 'selected' : '' ?>><?= htmlspecialchars($branchName) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                            <?php if (!empty($repoRefs['tags'])): ?>
                            <optgroup label="<?= htmlspecialchars(t('dashboard.repo_ref.tags')) ?>">
                                <?php foreach ($repoRefs['tags'] as $tagName): ?>
                                <option value="<?= htmlspecialchars($tagName) ?>" <?= $tagName === $selectedRepoRef ? 'selected' : '' ?>><?= htmlspecialchars($tagName) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                        </select>
                        <button type="button" onclick="applyRepoRef()" class="btn"><?= htmlspecialchars(t('dashboard.repo_ref.apply')) ?></button>
                        <button type="button" onclick="refreshRepoRefs()" class="btn btn-secondary"><?= htmlspecialchars(t('dashboard.repo_ref.refresh')) ?></button>
                    </div>
                    <p class="repo-ref-help"><?= htmlspecialchars(t('dashboard.repo_ref.help')) ?></p>
                    <span id="repoRefMessage"></span>
                    <?php if ($qcow2Supported): ?>
                    <div class="target-toggle" role="radiogroup" aria-label="<?= htmlspecialchars(t('dashboard.target')) ?>">
                        <label>
                            <input type="radio" name="target" value="iso" <?= $savedTarget === 'iso' ? 'checked' : '' ?> onchange="updateTargetOptions()">
                            <?= htmlspecialchars(t('dashboard.target.iso')) ?>
                        </label>
                        <label>
                            <input type="radio" name="target" value="qcow2" <?= $savedTarget === 'qcow2' ? 'checked' : '' ?> onchange="updateTargetOptions()">
                            <?= htmlspecialchars(t('dashboard.target.qcow2')) ?>
                        </label>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="target" value="iso">
                    <?php endif; ?>

                    <div id="isoOptions"<?= $savedTarget === 'iso' ? '' : ' style="display:none"' ?>>
                    <h3><?= htmlspecialchars(t('dashboard.package_classes')) ?></h3>
                    <div class="form-group">
                        <div class="package-list">
                            <?php foreach ($availablePackageClasses as $className): ?>
                            <label class="package-item">
                                <input type="checkbox" name="package_classes[]" value="<?= htmlspecialchars($className) ?>" <?= in_array($className, $savedPackageClasses, true) ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars($className) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; margin-top: 20px;">
                        <h3 style="margin: 0;"><?= htmlspecialchars(t('dashboard.boot_menu_items')) ?></h3>
                        <button type="button" onclick="addMenuItemList()" class="btn btn-secondary" style="font-size: 0.75em; padding: 4px 8px;"><?= htmlspecialchars(t('dashboard.add_menu_list')) ?></button>
                    </div>
                    <div class="form-group">
                        <div id="menuItemsList">
                            <!-- Dynamic list of menu items will be added here -->
                        </div>
                    </div>
                    <input type="hidden" name="menu_items_json" id="menuItemsJson">
                    </div>

                    <div id="qcow2Options"<?= $savedTarget === 'qcow2' ? '' : ' style="display:none"' ?>>
                    <div class="form-group">
                        <label><?= htmlspecialchars(t('dashboard.vmdisksize')) ?></label>
                        <input type="text" name="vmdisksize" value="<?= htmlspecialchars($savedVmDiskSize) ?>" placeholder="<?= htmlspecialchars(t('dashboard.vmdisksize.placeholder')) ?>" pattern="[0-9]+[KMGTkmgT]" title="<?= htmlspecialchars(t('dashboard.vmdisksize.placeholder')) ?>">
                    </div>
                    <div class="form-group">
                        <label class="package-item" style="min-width: auto;">
                            <input type="checkbox" name="cloud_init" value="1" <?= $savedCloudInit ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars(t('dashboard.cloud_init')) ?></span>
                        </label>
                    </div>
                    <p style="margin: 4px 0 0 0; font-size: 0.75em; color: #666;"><?= htmlspecialchars(t('dashboard.cloud_init.help')) ?></p>
                    </div>
                    
                    <div class="action-buttons" style="margin-top: 12px; padding-top: 10px; border-top: 1px solid #ddd;">
                        <button type="submit" name="action" value="save_and_build"><?= htmlspecialchars(t('dashboard.save_and_build')) ?></button>
                        <button type="button" onclick="saveConfiguration()" class="btn btn-secondary"><?= htmlspecialchars(t('dashboard.save_config')) ?></button>
                    </div>
                </div>
            </div>
        </form>
        
        <!-- Modal pour hasher le mot de passe -->
        <div id="passwordHashModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><?= htmlspecialchars(t('dashboard.hash_password.title')) ?></h3>
                    <span class="close" onclick="closePasswordHashModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <label><?= htmlspecialchars(t('dashboard.hash_password.label')) ?></label>
                    <input type="text" id="passwordInput" placeholder="<?= htmlspecialchars(t('dashboard.hash_password.placeholder')) ?>" autofocus>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closePasswordHashModal()" class="btn btn-secondary"><?= htmlspecialchars(t('dashboard.cancel')) ?></button>
                    <button type="button" onclick="hashPassword()" class="btn"><?= htmlspecialchars(t('dashboard.hash_password.button')) ?></button>
                </div>
            </div>
        </div>
        
        <div class="builds-section">
            <h2><?= htmlspecialchars(t('dashboard.builds')) ?></h2>
    <div id="buildsList"><?= htmlspecialchars(t('dashboard.builds.loading')) ?></div>
    
    <script>
        // Traductions JavaScript
        const translations = {
            'builds.status.building': <?= json_encode(t('dashboard.builds.status.building')) ?>,
            'builds.status.completed': <?= json_encode(t('dashboard.builds.status.completed')) ?>,
            'builds.status.failed': <?= json_encode(t('dashboard.builds.status.failed')) ?>,
            'builds.status.waiting': <?= json_encode(t('dashboard.builds.status.waiting_no_pos')) ?>,
            'builds.status.waiting_pos': <?= json_encode(t('dashboard.builds.status.waiting')) ?>,
            'builds.status.unknown': <?= json_encode(t('dashboard.builds.status.unknown')) ?>,
            'builds.none': <?= json_encode(t('dashboard.builds.none')) ?>,
            'builds.id': <?= json_encode(t('dashboard.builds.id')) ?>,
            'builds.type': <?= json_encode(t('dashboard.builds.type')) ?>,
            'builds.ref': <?= json_encode(t('dashboard.builds.ref')) ?>,
            'builds.status': <?= json_encode(t('dashboard.builds.status')) ?>,
            'builds.date': <?= json_encode(t('dashboard.builds.date')) ?>,
            'builds.actions': <?= json_encode(t('dashboard.builds.actions')) ?>,
            'builds.logs': <?= json_encode(t('dashboard.builds.logs')) ?>,
            'builds.delete': <?= json_encode(t('dashboard.builds.delete')) ?>,
            'builds.delete_confirm': <?= json_encode(t('dashboard.builds.delete_confirm')) ?>,
            'builds.download_iso': <?= json_encode(t('dashboard.builds.download_iso')) ?>,
            'builds.download_qcow2': <?= json_encode(t('dashboard.builds.download_qcow2')) ?>,
            'target.iso': <?= json_encode(t('dashboard.target.iso')) ?>,
            'target.qcow2': <?= json_encode(t('dashboard.target.qcow2')) ?>,
            'import.progress': <?= json_encode(t('dashboard.import.progress')) ?>,
            'import.error': <?= json_encode(t('dashboard.import.error')) ?>,
            'import.success': <?= json_encode(t('dashboard.import.success')) ?>,
            'common.delete': <?= json_encode(t('common.delete')) ?>,
            'common.list': <?= json_encode(t('common.list')) ?>,
            'common.min_one_list': <?= json_encode(t('common.min_one_list')) ?>,
            'common.save_error': <?= json_encode(t('common.save_error')) ?>,
            'hash_password.empty': <?= json_encode(t('dashboard.hash_password.empty')) ?>,
            'hash_password.processing': <?= json_encode(t('dashboard.hash_password.processing')) ?>,
            'hash_password.error': <?= json_encode(t('dashboard.hash_password.error')) ?>,
            'repo_ref.confirm': <?= json_encode(t('dashboard.repo_ref.confirm')) ?>,
            'repo_ref.switching': <?= json_encode(t('dashboard.repo_ref.switching')) ?>,
            'repo_ref.refreshing': <?= json_encode(t('dashboard.repo_ref.refreshing')) ?>,
            'repo_ref.error': <?= json_encode(t('dashboard.repo_ref.error')) ?>,
            'repo_ref.list_error': <?= json_encode(t('dashboard.repo_ref.list_error')) ?>
        };
        const currentRepoRef = <?= json_encode($selectedRepoRef) ?>;
        
        function getStatusLabel(status, queuePosition) {
            const labels = {
                'building': translations['builds.status.building'],
                'completed': translations['builds.status.completed'],
                'failed': translations['builds.status.failed'],
                'waiting': queuePosition ? translations['builds.status.waiting_pos'].replace('{position}', queuePosition) : translations['builds.status.waiting'],
                'unknown': translations['builds.status.unknown']
            };
            return labels[status] || status;
        }
        
        function getStatusClass(status) {
            const classes = {
                'building': 'status-building',
                'completed': 'status-completed',
                'failed': 'status-failed',
                'waiting': 'status-waiting',
                'unknown': 'status-unknown'
            };
            return classes[status] || 'status-unknown';
        }
        
        // Charger et afficher tous les builds
        function loadBuilds() {
            fetch('get_builds.php')
                .then(r => r.json())
                .then(builds => {
                    const list = document.getElementById('buildsList');
                    if (builds.length === 0) {
                        list.innerHTML = '<p style="padding: 8px; color: #666; font-size: 0.85em;">' + translations['builds.none'] + '</p>';
                    } else {
                        list.innerHTML = '<table>' +
                            '<thead><tr>' +
                            '<th>' + translations['builds.id'] + '</th>' +
                            '<th style="width: 70px;">' + translations['builds.type'] + '</th>' +
                            '<th style="width: 90px;">' + translations['builds.ref'] + '</th>' +
                            '<th style="width: 90px;">' + translations['builds.status'] + '</th>' +
                            '<th style="width: 130px;">' + translations['builds.date'] + '</th>' +
                            '<th style="width: 180px;">' + translations['builds.actions'] + '</th>' +
                            '</tr></thead><tbody>' +
                            builds.map(b => {
                                const statusClass = getStatusClass(b.status);
                                const shortDate = b.timestamp ? b.timestamp.substring(0, 16) : '';
                                const queuePosition = b.queue_position || null;
                                const target = b.target === 'qcow2' ? 'qcow2' : 'iso';
                                const targetLabel = translations['target.' + target];
                                let actions = '<a href="stream_logs.php?id=' + encodeURIComponent(b.id) + '">' + translations['builds.logs'] + '</a>';
                                
                                // Bouton Supprimer pour les builds en attente
                                if (b.status === 'waiting') {
                                    actions += ' | <a href="delete_build.php?id=' + encodeURIComponent(b.id) + '" onclick="return confirm(\'' + translations['builds.delete_confirm'] + '\');" style="color: #dc3545;">' + translations['builds.delete'] + '</a>';
                                }
                                
                                // Bouton Télécharger pour les builds terminés
                                if (b.status === 'completed') {
                                    const downloadLabel = target === 'qcow2'
                                        ? translations['builds.download_qcow2']
                                        : translations['builds.download_iso'];
                                    actions += ' | <a href="download.php?id=' + encodeURIComponent(b.id) + '" style="color: #28a745;">' + downloadLabel + '</a>';
                                }
                                
                                const buildIdDisplay = b.user ? `${b.id} (${b.user})` : b.id;
                                const repoRef = b.repo_ref ? b.repo_ref : '-';
                                return `<tr>` +
                                    `<td>${buildIdDisplay}</td>` +
                                    `<td>${targetLabel}</td>` +
                                    `<td style="font-family: monospace; font-size: 0.8em;">${repoRef}</td>` +
                                    `<td><span class="${statusClass}">${getStatusLabel(b.status, queuePosition)}</span></td>` +
                                    `<td>${shortDate}</td>` +
                                    `<td>${actions}</td>` +
                                    `</tr>`;
                            }).join('') +
                            '</tbody></table>';
                    }
                });
        }
            loadBuilds();
            // Rafraîchir toutes les 5 secondes pour mettre à jour les statuts
            setInterval(loadBuilds, 5000);
        </script>
        
        <script>
        // Fonction pour importer un fichier ZIP
        function importUsercustomization(event) {
            if (event) {
                event.preventDefault();
            }
            
            const form = document.getElementById('importForm');
            const fileInput = document.getElementById('zipFileInput');
            
            if (!fileInput.files || fileInput.files.length === 0) {
                return;
            }
            
            const formData = new FormData(form);
            const messageDiv = document.getElementById('importMessage');
            
            messageDiv.innerHTML = '<span style="color: #17a2b8;">' + translations['import.progress'] + '</span>';
            
            fetch('import_usercustomization.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    messageDiv.innerHTML = '<span style="color: #dc3545;">' + translations['import.error'].replace('{error}', data.error) + '</span>';
                } else {
                    messageDiv.innerHTML = '<span style="color: #28a745;">' + translations['import.success'].replace('{message}', data.message) + '</span>';
                    // Recharger la page après 2 secondes pour voir les changements
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                }
            })
            .catch(err => {
                messageDiv.innerHTML = '<span style="color: #dc3545;">' + translations['import.error'].replace('{error}', err) + '</span>';
            });
            
            // Réinitialiser le champ file
            form.reset();
        }
        
        function updateTargetOptions() {
            const target = document.querySelector('input[name="target"]:checked')?.value || 'iso';
            const isIso = target === 'iso';
            const isoOptions = document.getElementById('isoOptions');
            const qcow2Options = document.getElementById('qcow2Options');
            if (isoOptions) {
                isoOptions.style.display = isIso ? '' : 'none';
            }
            if (qcow2Options) {
                qcow2Options.style.display = isIso ? 'none' : '';
            }
            const diskInput = document.querySelector('input[name="vmdisksize"]');
            if (diskInput) {
                diskInput.required = !isIso;
            }
        }
        updateTargetOptions();

        function setRepoRefMessage(text, color) {
            const messageDiv = document.getElementById('repoRefMessage');
            if (!messageDiv) {
                return;
            }
            if (!text) {
                messageDiv.innerHTML = '';
                return;
            }
            messageDiv.innerHTML = '<span style="color: ' + color + '; font-size: 0.75em;">' + text + '</span>';
        }

        function applyRepoRef() {
            const select = document.getElementById('repoRefSelect');
            const ref = select ? select.value : '';
            if (!ref) {
                return;
            }
            if (!confirm(translations['repo_ref.confirm'])) {
                select.value = currentRepoRef;
                return;
            }
            const applyBtn = document.querySelector('button[onclick="applyRepoRef()"]');
            const refreshBtn = document.querySelector('button[onclick="refreshRepoRefs()"]');
            if (applyBtn) applyBtn.disabled = true;
            if (refreshBtn) refreshBtn.disabled = true;
            setRepoRefMessage(translations['repo_ref.switching'], '#17a2b8');
            const formData = new FormData();
            formData.append('ref', ref);
            formData.append('action', 'switch');
            fetch('switch_repo.php', { method: 'POST', body: formData })
                .then(r => r.json().then(data => ({ ok: r.ok, data })))
                .then(({ ok, data }) => {
                    if (!ok || !data.ok) {
                        setRepoRefMessage(translations['repo_ref.error'], '#dc3545');
                        select.value = currentRepoRef;
                        if (applyBtn) applyBtn.disabled = false;
                        if (refreshBtn) refreshBtn.disabled = false;
                        return;
                    }
                    window.location.href = 'dashboard.php?ref_switched=1';
                })
                .catch(() => {
                    setRepoRefMessage(translations['repo_ref.error'], '#dc3545');
                    select.value = currentRepoRef;
                    if (applyBtn) applyBtn.disabled = false;
                    if (refreshBtn) refreshBtn.disabled = false;
                });
        }

        function refreshRepoRefs() {
            const applyBtn = document.querySelector('button[onclick="applyRepoRef()"]');
            const refreshBtn = document.querySelector('button[onclick="refreshRepoRefs()"]');
            if (applyBtn) applyBtn.disabled = true;
            if (refreshBtn) refreshBtn.disabled = true;
            setRepoRefMessage(translations['repo_ref.refreshing'], '#17a2b8');
            const formData = new FormData();
            formData.append('action', 'refresh_list');
            fetch('switch_repo.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (!data.ok) {
                        setRepoRefMessage(translations['repo_ref.list_error'], '#dc3545');
                        if (applyBtn) applyBtn.disabled = false;
                        if (refreshBtn) refreshBtn.disabled = false;
                        return;
                    }
                    window.location.reload();
                })
                .catch(() => {
                    setRepoRefMessage(translations['repo_ref.list_error'], '#dc3545');
                    if (applyBtn) applyBtn.disabled = false;
                    if (refreshBtn) refreshBtn.disabled = false;
                });
        }

        // Gestion des Boot Menu Items (flags issus de build_iso.sh)
        const menuItemOptions = <?= json_encode(array_values($availableMenuFlags), JSON_UNESCAPED_UNICODE) ?>;
        // Initialiser avec les valeurs sauvegardées depuis PHP
        let menuItemLists = <?= json_encode($savedMenuItems) ?>;
        
        function renderMenuItemLists() {
            const container = document.getElementById('menuItemsList');
            container.innerHTML = '';
            
            menuItemLists.forEach((list, listIndex) => {
                const div = document.createElement('div');
                div.className = 'menu-item-list';
                div.innerHTML = `
                    <div class="menu-item-list-header">
                        <span class="menu-item-list-title">${translations['common.list']} ${listIndex + 1}</span>
                        <button type="button" onclick="removeMenuItemList(${listIndex})" style="background: #dc3545; color: white; border: none; padding: 5px 10px; cursor: pointer; border-radius: 3px;">
                            ${translations['common.delete']}
                        </button>
                    </div>
                    <div class="menu-item-checkboxes">
                        ${menuItemOptions.map(option => `
                            <label>
                                <input type="checkbox" value="${option}" ${list.includes(option) ? 'checked' : ''} 
                                       onchange="updateMenuItemList(${listIndex}, '${option}', this.checked)">
                                ${option}
                            </label>
                        `).join('')}
                    </div>
                `;
                container.appendChild(div);
            });
            
            updateMenuItemsJson();
        }
        
        function addMenuItemList() {
            menuItemLists.push([]);
            renderMenuItemLists();
        }
        
        function removeMenuItemList(index) {
            if (menuItemLists.length > 1) {
                menuItemLists.splice(index, 1);
                renderMenuItemLists();
            } else {
                alert(translations['common.min_one_list']);
            }
        }
        
        function updateMenuItemList(listIndex, option, checked) {
            const list = menuItemLists[listIndex];
            if (checked) {
                if (!list.includes(option)) {
                    list.push(option);
                }
            } else {
                const index = list.indexOf(option);
                if (index > -1) {
                    list.splice(index, 1);
                }
            }
            updateMenuItemsJson();
        }
        
        function updateMenuItemsJson() {
            document.getElementById('menuItemsJson').value = JSON.stringify(menuItemLists);
        }
        
        // Initialiser l'affichage au chargement
        renderMenuItemLists();
        
        // Soumettre le formulaire avec les données JSON
        document.getElementById('buildForm').addEventListener('submit', function(e) {
            updateMenuItemsJson();
        });
        
        // Fonction pour sauvegarder la configuration sans lancer la build
        function saveConfiguration() {
            const form = document.getElementById('buildForm');
            const formData = new FormData(form);
            formData.append('action', 'save_only');
            
            fetch('build.php', {
                method: 'POST',
                body: formData,
                redirect: 'follow'
            })
            .then(r => {
                // Si la réponse est une redirection, on suit automatiquement
                if (r.ok || r.redirected) {
                    window.location.href = 'dashboard.php?saved=1';
                } else {
                    return r.text().then(text => {
                        alert(translations['common.save_error'].replace('{error}', text));
                    });
                }
            })
            .catch(err => {
                // En cas d'erreur réseau, on essaie quand même de recharger
                // car le serveur a peut-être bien traité la requête
                console.error('Erreur:', err);
                window.location.href = 'dashboard.php?saved=1';
            });
        }
        // Fonctions pour gérer le modal de hashage de mot de passe
        function openPasswordHashModal() {
            document.getElementById('passwordHashModal').style.display = 'block';
            document.getElementById('passwordInput').value = '';
            document.getElementById('passwordInput').focus();
        }
        
        function closePasswordHashModal() {
            document.getElementById('passwordHashModal').style.display = 'none';
            document.getElementById('passwordInput').value = '';
        }
        
        // Fermer le modal si on clique en dehors
        window.onclick = function(event) {
            const modal = document.getElementById('passwordHashModal');
            if (event.target === modal) {
                closePasswordHashModal();
            }
        }
        
        // Fermer le modal avec la touche Escape
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closePasswordHashModal();
            }
        });
        
        // Soumettre le mot de passe avec Enter
        document.getElementById('passwordInput').addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                hashPassword();
            }
        });
        
        function hashPassword() {
            const password = document.getElementById('passwordInput').value.trim();
            
            if (!password) {
                alert(translations['hash_password.empty'] || 'Le mot de passe ne peut pas être vide');
                return;
            }
            
            // Afficher un indicateur de chargement
            const button = document.querySelector('#passwordHashModal .modal-footer button:last-child');
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = translations['hash_password.processing'] || 'Traitement...';
            
            // Appeler l'API pour hasher le mot de passe
            const formData = new FormData();
            formData.append('password', password);
            
            fetch('hash_password.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                } else if (data.hash) {
                    // Remplir le champ USERPW avec le hash
                    document.getElementById('userpwField').value = data.hash;
                    closePasswordHashModal();
                } else {
                    alert(translations['hash_password.error'] || 'Erreur lors du hashage du mot de passe');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert(translations['hash_password.error'] || 'Erreur lors du hashage du mot de passe');
            })
            .finally(() => {
                button.disabled = false;
                button.textContent = originalText;
            });
        }
        </script>
    </div>
</body>
</html>

