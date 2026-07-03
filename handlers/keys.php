<?php
/**
 * POST /keys/crear — solo uso interno.
 * Body JSON: { "proyecto": "MeUnoColonia", "admin_secret": "****" }
 */

$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    jsonResponse(false, 'Body JSON inválido', 400);
}

$proyecto = trim($body['proyecto'] ?? '');
$adminSecret = $body['admin_secret'] ?? '';

if ($proyecto === '') {
    jsonResponse(false, 'El campo "proyecto" es requerido', 400);
}

$e = env();
if (!hash_equals($e['ADMIN_SECRET'], (string) $adminSecret)) {
    jsonResponse(false, 'admin_secret inválido', 401);
}

$apiKey = 'col_' . bin2hex(random_bytes(32));
$hash = hash('sha256', $apiKey);

$db = getDB();
$stmt = $db->prepare(
    'INSERT INTO api_keys (proyecto, key_hash, activa) VALUES (?, ?, 1)'
);
$stmt->execute([$proyecto, $hash]);

jsonResponse(true, [
    'proyecto' => $proyecto,
    'api_key' => $apiKey,
]);
