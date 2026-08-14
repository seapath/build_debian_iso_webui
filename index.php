<?php
require_once 'config.php';
require_once 'auth.php';

// Gérer la déconnexion
if (isset($_GET['logout'])) {
    logout();
    header('Location: index.php');
    exit;
}

if (isAuthenticated()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (login($username, $password)) {
        header('Location: dashboard.php');
        exit;
    } else {
        $error = t('login.error');
    }
}
$currentLang = getLanguage();
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars(t('login.title')) ?></title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 400px; margin: 100px auto; padding: 20px; }
        input { width: 100%; padding: 10px; margin: 10px 0; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #007bff; color: white; border: none; cursor: pointer; }
        .error { color: red; margin: 10px 0; }
        .lang-selector { text-align: right; margin-bottom: 10px; }
        .lang-selector a { margin: 0 5px; text-decoration: none; color: #007bff; }
        .lang-selector a.active { font-weight: bold; }
    </style>
</head>
<body>
    <div class="lang-selector">
        <a href="?lang=fr" class="<?= $currentLang === 'fr' ? 'active' : '' ?>">FR</a> | 
        <a href="?lang=en" class="<?= $currentLang === 'en' ? 'active' : '' ?>">EN</a>
    </div>
    <h1><?= htmlspecialchars(t('login.heading')) ?></h1>
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <input type="text" name="username" placeholder="<?= htmlspecialchars(t('login.username')) ?>" required>
        <input type="password" name="password" placeholder="<?= htmlspecialchars(t('login.password')) ?>" required>
        <button type="submit"><?= htmlspecialchars(t('login.submit')) ?></button>
    </form>
</body>
</html>
