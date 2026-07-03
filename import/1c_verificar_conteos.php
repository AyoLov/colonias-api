<?php
/**
 * Verifica los conteos esperados tras la importación SEPOMEX (M3.3).
 * Uso: php import/1c_verificar_conteos.php
 */

require_once __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse por CLI.\n");
    exit(1);
}

$db = getDB();

$colonias = (int) $db->query('SELECT COUNT(*) FROM colonias')->fetchColumn();
$municipios = (int) $db->query('SELECT COUNT(*) FROM municipios')->fetchColumn();
$sinMunicipio = (int) $db->query('SELECT COUNT(*) FROM colonias WHERE municipio_id IS NULL')->fetchColumn();

echo "colonias:        $colonias (esperado > 140,000)\n";
echo "municipios:       $municipios (esperado > 2,400)\n";
echo "sin municipio_id: $sinMunicipio (esperado 0)\n";

$ok = $colonias > 140000 && $municipios > 2400 && $sinMunicipio === 0;
echo $ok ? "OK\n" : "REVISAR: alguno de los conteos no cumple lo esperado.\n";
exit($ok ? 0 : 1);
