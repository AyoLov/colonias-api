<?php
/**
 * Caché de archivos planos en cache/responses/.
 * No hay Redis en el hosting compartido, así que cada respuesta se
 * serializa a un archivo cuyo nombre es md5(key).json.
 */

function cacheDir(): string
{
    return __DIR__ . '/../cache/responses';
}

function cachePath(string $key): string
{
    return cacheDir() . '/' . md5($key) . '.json';
}

/**
 * Devuelve el valor cacheado o null si no existe o expiró.
 */
function cacheGet(string $key)
{
    $path = cachePath($key);
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    $wrapper = json_decode($raw, true);

    if (!is_array($wrapper) || !isset($wrapper['expira_en'], $wrapper['data'])) {
        return null;
    }

    if (time() > $wrapper['expira_en']) {
        @unlink($path);
        return null;
    }

    return $wrapper['data'];
}

/**
 * Guarda un valor en caché con TTL en segundos (default: env CACHE_TTL).
 */
function cacheSet(string $key, $data, ?int $ttl = null): void
{
    $ttl = $ttl ?? (env()['CACHE_TTL'] ?? 86400);

    $dir = cacheDir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $wrapper = [
        'expira_en' => time() + $ttl,
        'data' => $data,
    ];

    file_put_contents(cachePath($key), json_encode($wrapper, JSON_UNESCAPED_UNICODE));
}
