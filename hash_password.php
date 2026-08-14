<?php
require_once 'config.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$password = $_POST['password'] ?? '';

if (empty($password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Password is required']);
    exit;
}

// Générer un salt aléatoire pour sha512crypt
// Format: $6$rounds=5000$salt$hash
// Le salt doit faire 16 caractères aléatoires
$salt = substr(str_replace('+', '.', base64_encode(random_bytes(12))), 0, 16);

// Hasher le mot de passe avec sha512crypt
// $6$ indique sha512crypt, rounds=5000 est la valeur par défaut
$hash = crypt($password, '$6$rounds=5000$' . $salt . '$');

echo json_encode(['hash' => $hash]);
?>
