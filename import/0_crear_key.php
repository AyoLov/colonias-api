<?php
/**
 * Script CLI para insertar manualmente la primera API key de un proyecto.
 * Uso: php import/0_crear_key.php "NombreProyecto"
 */

require_once __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse por CLI.\n");
    exit(1);
}

$proyecto = trim($argv[1] ?? '');
if ($proyecto === '') {
    fwrite(STDERR, "Uso: php import/0_crear_key.php \"NombreProyecto\"\n");
    exit(1);
}

$apiKey = 'col_' . bin2hex(random_bytes(32));
$hash = hash('sha256', $apiKey);

$db = getDB();
$stmt = $db->prepare(
    'INSERT INTO api_keys (proyecto, key_hash, activa) VALUES (?, ?, 1)'
);
$stmt->execute([$proyecto, $hash]);

echo "Proyecto: $proyecto\n";
echo "API key (guárdala, no se puede recuperar): $apiKey\n";
