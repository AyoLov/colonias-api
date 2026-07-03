<?php
/**
 * Reporte de salud de la BD: conteos, % de polígonos, queries de prueba
 * y tiempo de respuesta. Uso: php import/3_verificar.php
 */

require_once __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse por CLI.\n");
    exit(1);
}

$db = getDB();

echo "=== Conteos ===\n";
$estados = (int) $db->query('SELECT COUNT(*) FROM estados')->fetchColumn();
$municipios = (int) $db->query('SELECT COUNT(*) FROM municipios')->fetchColumn();
$colonias = (int) $db->query('SELECT COUNT(*) FROM colonias')->fetchColumn();
$poligonos = (int) $db->query('SELECT COUNT(*) FROM colonia_poligonos')->fetchColumn();

echo "estados:           $estados (esperado 32)\n";
echo "municipios:        $municipios (esperado > 2,400)\n";
echo "colonias:          $colonias (esperado > 140,000)\n";
echo "colonia_poligonos: $poligonos\n";

$porcentaje = $colonias > 0 ? round($poligonos * 100 / $colonias, 1) : 0;
echo "cobertura de polígonos: $porcentaje% (esperado > 75%)\n";

echo "\n=== Índice espacial ===\n";
$indices = $db->query("SHOW INDEX FROM colonia_poligonos WHERE Key_name = 'sp_poligono'")->fetchAll();
echo $indices ? "sp_poligono existe (SPATIAL)\n" : "FALTA sp_poligono\n";

echo "\n=== Queries de prueba ===\n";
$pruebas = [
    'estados' => 'SELECT COUNT(*) FROM estados',
    'buscar por nombre (roma)' => "SELECT COUNT(*) FROM colonias WHERE MATCH(nombre) AGAINST ('roma')",
    'buscar por CP' => "SELECT COUNT(*) FROM colonias WHERE codigo_postal = '06600'",
    'municipios de CDMX' => "SELECT COUNT(*) FROM municipios m JOIN estados e ON e.id = m.estado_id WHERE e.clave = '09'",
];

foreach ($pruebas as $nombre => $sql) {
    $inicio = microtime(true);
    try {
        $resultado = $db->query($sql)->fetchColumn();
        $ms = round((microtime(true) - $inicio) * 1000, 1);
        echo "[$ms ms] $nombre => $resultado\n";
    } catch (Throwable $e) {
        echo "[ERROR] $nombre => " . $e->getMessage() . "\n";
    }
}

echo "\nReporte completo.\n";
