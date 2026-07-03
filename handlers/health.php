<?php
/**
 * GET /health — endpoint público de monitoreo (sin autenticación).
 */

$inicio = microtime(true);

try {
    $db = getDB();
    $colonias = (int) $db->query('SELECT COUNT(*) FROM colonias')->fetchColumn();
    $latenciaMs = (int) round((microtime(true) - $inicio) * 1000);

    jsonResponse(true, [
        'status' => 'ok',
        'colonias' => $colonias,
        'latencia_bd_ms' => $latenciaMs,
    ], 200, $startTime);
} catch (Throwable $e) {
    jsonResponse(false, 'Error de conexión a la base de datos', 500);
}
