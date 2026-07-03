<?php
/**
 * Rate limiting básico: máximo RATE_LIMIT_PER_MIN requests por minuto,
 * por API key, usando la tabla api_rate_log.
 */

function enforceRateLimit(int $apiKeyId): void
{
    $limite = (int) (env()['RATE_LIMIT_PER_MIN'] ?? 100);
    $minuto = date('Y-m-d H:i:00');

    $db = getDB();

    $db->prepare(
        'INSERT INTO api_rate_log (api_key_id, minuto, conteo) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE conteo = conteo + 1'
    )->execute([$apiKeyId, $minuto]);

    $stmt = $db->prepare(
        'SELECT conteo FROM api_rate_log WHERE api_key_id = ? AND minuto = ?'
    );
    $stmt->execute([$apiKeyId, $minuto]);
    $conteo = (int) $stmt->fetchColumn();

    if ($conteo > $limite) {
        jsonResponse(false, 'Demasiadas solicitudes, intenta de nuevo en un momento', 429);
    }
}
