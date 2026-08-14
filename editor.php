<?php
require_once 'config.php';
requireAuth();

// S'assure que le dépôt est bien cloné et que usercustomization existe
$userCustomizationPath = getSessionUserCustomizationPath();
$currentLang = getLanguage();
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars(t('editor.title')) ?></title>
    <style>
        body { font-family: Arial, sans-serif; display: flex; height: 100vh; margin: 0; }
        .sidebar { width: 320px; border-right: 1px solid #ccc; padding: 10px; box-sizing: border-box; overflow-y: auto; }
        .sidebar h3 { margin-top: 10px; margin-bottom: 8px; font-size: 14px; }
        .sidebar h3:first-child { margin-top: 0; }
        .main { flex: 1; display: flex; flex-direction: column; }
        .toolbar { padding: 10px; border-bottom: 1px solid #ccc; }
        .editor { flex: 1; padding: 10px; box-sizing: border-box; }
        textarea { width: 100%; height: 100%; box-sizing: border-box; font-family: monospace; font-size: 13px; }
        textarea[readonly] { background-color: #f5f5f5; cursor: not-allowed; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        .toolbar { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
        .toolbar .path { flex: 1; min-width: 200px; }
        .executable-toggle { display: inline-flex; align-items: center; gap: 4px; font-size: 13px; white-space: nowrap; }
        .executable-toggle.disabled { opacity: 0.5; pointer-events: none; }
        ul { list-style: none; padding-left: 15px; margin: 4px 0; }
        li { margin: 2px 0; }
        a { cursor: pointer; color: #007bff; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .path { font-family: monospace; font-size: 12px; color: #555; }
        input[type="text"] { width: 100%; box-sizing: border-box; }
        button { margin-right: 5px; }
        hr { margin: 12px 0; border: none; border-top: 1px solid #ddd; }
        .srv-folder-toggle {
            cursor: pointer;
            display: inline-block;
            width: 14px;
            text-align: center;
            margin-right: 2px;
            color: #666;
            font-weight: bold;
            user-select: none;
            font-family: monospace;
        }
        .srv-folder-toggle:hover { color: #333; }
        .srv-folder-content { display: none; }
        .srv-folder-content.expanded { display: block; }
        .srv-folder-name { cursor: pointer; font-weight: bold; color: #333; }
        .srv-folder-name:hover { text-decoration: underline; }
        .lang-selector { text-align: right; margin-bottom: 8px; font-size: 13px; }
        .lang-selector a { margin: 0 4px; text-decoration: none; color: #007bff; }
        .lang-selector a.active { font-weight: bold; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="lang-selector">
            <a href="?lang=fr" class="<?= $currentLang === 'fr' ? 'active' : '' ?>">FR</a>
            <span>|</span>
            <a href="?lang=en" class="<?= $currentLang === 'en' ? 'active' : '' ?>">EN</a>
        </div>
        <h3><?= htmlspecialchars(t('editor.usercustomization')) ?></h3>
        <div id="tree"><?= htmlspecialchars(t('editor.loading')) ?></div>
        <hr>
        <div>
            <h4><?= htmlspecialchars(t('editor.new_item')) ?></h4>
            <label><?= htmlspecialchars(t('editor.path_relative')) ?></label>
            <input type="text" id="newPath">
            <label>
                <input type="checkbox" id="isDir" onchange="updateCreateExecutable()"> <?= htmlspecialchars(t('editor.is_directory')) ?>
            </label>
            <label id="createExecutableLabel" style="display: none;">
                <input type="checkbox" id="createExecutable"> <?= htmlspecialchars(t('editor.executable')) ?>
            </label>
            <button onclick="createPath()"><?= htmlspecialchars(t('editor.create')) ?></button>
        </div>
        <hr>
        <h3><?= htmlspecialchars(t('editor.srv_fai_config')) ?></h3>
        <div id="srvTree"><?= htmlspecialchars(t('editor.loading')) ?></div>
    </div>
    <div class="main">
        <div class="toolbar">
            <span class="path" id="currentPath"></span>
            <label id="executableToggle" class="executable-toggle disabled" title="<?= htmlspecialchars(t('editor.executable_title')) ?>">
                <input type="checkbox" id="executableCheckbox" onchange="toggleExecutable()" disabled>
                <?= htmlspecialchars(t('editor.executable')) ?>
            </label>
            <button onclick="saveFile()"><?= htmlspecialchars(t('editor.save')) ?></button>
            <button onclick="deletePath()"><?= htmlspecialchars(t('editor.delete')) ?></button>
        </div>
        <div class="editor">
            <textarea id="editorArea" placeholder="<?= htmlspecialchars(t('editor.select_file')) ?>"></textarea>
        </div>
    </div>

    <script>
        const translations = {
            empty: <?= json_encode(t('editor.empty')) ?>,
            readonly: <?= json_encode(t('editor.readonly')) ?>,
            chmod_error: <?= json_encode(t('editor.chmod_error')) ?>,
            no_editable_file: <?= json_encode(t('editor.no_editable_file')) ?>,
            no_deletable_file: <?= json_encode(t('editor.no_deletable_file')) ?>,
            delete_confirm: <?= json_encode(t('editor.delete_confirm')) ?>,
            path_required: <?= json_encode(t('editor.path_required')) ?>
        };

        let currentFile = null;
        let isReadOnly = false;

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, function (c) {
                return ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                })[c];
            });
        }

        function loadTree() {
            fetch('editor_api.php?action=list')
                .then(r => r.json())
                .then(data => {
                    document.getElementById('tree').innerHTML = renderTree(data, '');
                });
        }

        function loadSrvTree() {
            fetch('editor_api.php?action=list_srv')
                .then(r => r.json())
                .then(data => {
                    document.getElementById('srvTree').innerHTML = renderSrvTree(data, '');
                });
        }

        function renderTree(node, prefix) {
            if (!node || !node.children) return '<p>' + escapeHtml(translations.empty) + '</p>';
            let html = '<ul>';
            node.children.forEach(child => {
                const path = prefix ? prefix + '/' + child.name : child.name;
                if (child.type === 'dir') {
                    const safeName = escapeHtml(child.name);
                    html += '<li><strong>' + safeName + '/</strong>' +
                        renderTree(child, path) + '</li>';
                } else {
                    const safeName = escapeHtml(child.name);
                    const safePath = encodeURIComponent(path).replace(/'/g, '%27');
                    html += '<li><a onclick="openFile(\'' + safePath + '\')">' +
                        safeName + '</a></li>';
                }
            });
            html += '</ul>';
            return html;
        }

        function renderSrvTree(node, prefix, depth = 0) {
            if (!node || !node.children || node.children.length === 0) {
                if (depth === 0) return '<p>' + escapeHtml(translations.empty) + '</p>';
                return '';
            }

            let html = '<ul>';
            node.children.forEach((child) => {
                const path = prefix ? prefix + '/' + child.name : child.name;
                const safeId = 'srv_' + path.replace(/[^a-zA-Z0-9]/g, '_');

                if (child.type === 'dir') {
                    const safeName = escapeHtml(child.name);
                    html += '<li>';
                    html += '<span class="srv-folder-toggle" onclick="toggleSrvFolder(\'' + safeId + '\')" id="toggle_' + safeId + '">+</span>';
                    html += '<span class="srv-folder-name" onclick="toggleSrvFolder(\'' + safeId + '\')">' + safeName + '/</span>';
                    html += '<div class="srv-folder-content" id="content_' + safeId + '">';
                    html += renderSrvTree(child, path, depth + 1);
                    html += '</div>';
                    html += '</li>';
                } else {
                    const safeName = escapeHtml(child.name);
                    const safePath = encodeURIComponent(path).replace(/'/g, '%27');
                    html += '<li style="padding-left: 16px; margin-left: 0;">';
                    html += '<a onclick="openSrvFile(\'' + safePath + '\')" style="color: #6c757d;">' + safeName + '</a>';
                    html += ' <span style="color: #999; font-size: 0.85em;">' + escapeHtml(translations.readonly) + '</span>';
                    html += '</li>';
                }
            });
            html += '</ul>';
            return html;
        }

        function toggleSrvFolder(id) {
            const content = document.getElementById('content_' + id);
            const toggle = document.getElementById('toggle_' + id);

            if (!content || !toggle) return;

            if (content.classList.contains('expanded')) {
                content.classList.remove('expanded');
                toggle.textContent = '+';
            } else {
                content.classList.add('expanded');
                toggle.textContent = '-';
            }
        }

        function openFile(encodedPath) {
            const relPath = decodeURIComponent(encodedPath);
            fetch('editor_api.php?action=get&path=' + encodeURIComponent(relPath))
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    currentFile = relPath;
                    isReadOnly = false;
                    document.getElementById('currentPath').textContent = 'usercustomization/' + relPath;
                    document.getElementById('editorArea').value = data.content;
                    document.getElementById('editorArea').readOnly = false;
                    setExecutableCheckbox(!!data.executable);
                    updateToolbarButtons();
                });
        }

        function openSrvFile(encodedPath) {
            const relPath = decodeURIComponent(encodedPath);
            fetch('editor_api.php?action=get_srv&path=' + encodeURIComponent(relPath))
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    currentFile = null;
                    isReadOnly = true;
                    document.getElementById('currentPath').textContent = 'srv_fai_config/' + relPath + ' ' + translations.readonly;
                    document.getElementById('editorArea').value = data.content;
                    document.getElementById('editorArea').readOnly = true;
                    setExecutableCheckbox(false);
                    updateToolbarButtons();
                });
        }

        function updateToolbarButtons() {
            const saveBtn = document.querySelector('button[onclick="saveFile()"]');
            const deleteBtn = document.querySelector('button[onclick="deletePath()"]');
            const execToggle = document.getElementById('executableToggle');
            const execCheckbox = document.getElementById('executableCheckbox');
            if (isReadOnly || !currentFile) {
                if (saveBtn) saveBtn.disabled = true;
                if (deleteBtn) deleteBtn.disabled = true;
                if (execToggle) execToggle.classList.add('disabled');
                if (execCheckbox) execCheckbox.disabled = true;
            } else {
                if (saveBtn) saveBtn.disabled = false;
                if (deleteBtn) deleteBtn.disabled = false;
                if (execToggle) execToggle.classList.remove('disabled');
                if (execCheckbox) execCheckbox.disabled = false;
            }
        }

        function updateCreateExecutable() {
            const isDir = document.getElementById('isDir').checked;
            const label = document.getElementById('createExecutableLabel');
            if (isDir) {
                label.style.display = 'none';
                document.getElementById('createExecutable').checked = false;
            } else {
                label.style.display = 'block';
            }
        }

        function setExecutableCheckbox(checked) {
            document.getElementById('executableCheckbox').checked = checked;
        }

        function toggleExecutable() {
            if (!currentFile || isReadOnly) return;
            const executable = document.getElementById('executableCheckbox').checked;
            const body = new FormData();
            body.append('action', 'chmod');
            body.append('path', currentFile);
            body.append('executable', executable ? '1' : '0');
            fetch('editor_api.php', { method: 'POST', body })
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        setExecutableCheckbox(!executable);
                        return;
                    }
                    setExecutableCheckbox(!!data.executable);
                })
                .catch(() => {
                    alert(translations.chmod_error);
                    setExecutableCheckbox(!executable);
                });
        }

        function saveFile() {
            if (!currentFile || isReadOnly) {
                alert(translations.no_editable_file);
                return;
            }
            const body = new FormData();
            body.append('action', 'save');
            body.append('path', currentFile);
            body.append('content', document.getElementById('editorArea').value);
            body.append('executable', document.getElementById('executableCheckbox').checked ? '1' : '0');
            fetch('editor_api.php', { method: 'POST', body })
                .then(r => r.json())
                .then(data => {
                    if (data.error) alert(data.error);
                });
        }

        function deletePath() {
            if (!currentFile || isReadOnly) {
                alert(translations.no_deletable_file);
                return;
            }
            if (!confirm(translations.delete_confirm.replace('{path}', currentFile))) return;
            const body = new FormData();
            body.append('action', 'delete');
            body.append('path', currentFile);
            fetch('editor_api.php', { method: 'POST', body })
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                    } else {
                        currentFile = null;
                        isReadOnly = false;
                        document.getElementById('currentPath').textContent = '';
                        document.getElementById('editorArea').value = '';
                        document.getElementById('editorArea').readOnly = false;
                        setExecutableCheckbox(false);
                        updateToolbarButtons();
                        loadTree();
                    }
                });
        }

        function createPath() {
            const relPath = document.getElementById('newPath').value.trim();
            const isDir = document.getElementById('isDir').checked;
            if (!relPath) {
                alert(translations.path_required);
                return;
            }
            const body = new FormData();
            body.append('action', 'create');
            body.append('path', relPath);
            body.append('type', isDir ? 'dir' : 'file');
            if (!isDir && document.getElementById('createExecutable').checked) {
                body.append('executable', '1');
            }
            fetch('editor_api.php', { method: 'POST', body })
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                    } else {
                        document.getElementById('newPath').value = '';
                        document.getElementById('isDir').checked = false;
                        document.getElementById('createExecutable').checked = false;
                        updateCreateExecutable();
                        loadTree();
                    }
                });
        }

        updateToolbarButtons();
        loadTree();
        loadSrvTree();

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('open') === 'USERCUSTOMIZATION.var') {
            setTimeout(() => {
                openFile(encodeURIComponent('class/USERCUSTOMIZATION.var'));
            }, 500);
        }
    </script>
</body>
</html>
