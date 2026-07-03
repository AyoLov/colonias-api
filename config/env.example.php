<?php
/**
 * Copiar este archivo a config/env.php y llenar con los valores reales.
 * config/env.php NUNCA se sube al repo (ver .gitignore).
 */

return [
    // Conexión a MySQL
    'DB_HOST' => '127.0.0.1',
    'DB_NAME' => 'colonias_mx',
    'DB_USER' => 'tu_usuario',
    'DB_PASS' => 'tu_password',
    'DB_PORT' => 3306,

    // Secreto para crear API keys nuevas (POST /keys/crear)
    'ADMIN_SECRET' => 'cambia_este_valor_por_uno_seguro',

    // TTL del caché de archivos, en segundos (24h = 86400)
    'CACHE_TTL' => 86400,

    // Límite de requests por minuto por API key (M7)
    'RATE_LIMIT_PER_MIN' => 100,
];
