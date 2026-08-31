<?php
require_once 'config.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$action = $_POST['action'] ?? 'switch';
$ref = trim((string) ($_POST['ref'] ?? ''));

if ($action === 'refresh_list') {
    $refs = listSeapathRepoRefs(true);
    echo json_encode(['ok' => true, 'refs' => $refs, 'current' => getSelectedRepoRef()]);
    exit;
}

$refs = listSeapathRepoRefs();
$allowed = array_merge($refs['branches'], $refs['tags']);
$current = getSelectedRepoRef();

if ($ref === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid']);
    exit;
}

// Autoriser la ref courante même si elle n'est plus dans le cache distant
if (!in_array($ref, $allowed, true) && $ref !== $current) {
    $refs = listSeapathRepoRefs(true);
    $allowed = array_merge($refs['branches'], $refs['tags']);
}

if (!in_array($ref, $allowed, true) && $ref !== $current) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown_ref']);
    exit;
}

if (!checkoutSeapathRepoRef($ref)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'checkout']);
    exit;
}

echo json_encode(['ok' => true, 'ref' => $ref]);
