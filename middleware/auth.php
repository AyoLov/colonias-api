<?php
/**
 * Middleware de autenticación por API key.
 * Lee la key del header Authorization: Bearer o del parámetro ?api_key=,
 * la valida contra api_keys.key_hash (SHA-256) y actualiza ultimo_uso.
 */

function requireApiKey(): array
{
    $key = extractApiKey();

    if ($key === null || $key === '') {
        jsonResponse(false, 'API key requerida', 401);
    }

    $hash = hash('sha256', $key);

    $db = getDB();
    $stmt = $db->prepare(
        'SELECT id, proyecto, activa FROM api_keys WHERE key_hash = ? LIMIT 1'
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if (!$row || (int) $row['activa'] !== 1) {
        jsonResponse(false, 'API key inválida', 401);
    }

    $update = $db->prepare('UPDATE api_keys SET ultimo_uso = NOW() WHERE id = ?');
    $update->execute([$row['id']]);

    return $row;
}

function extractApiKey(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }

    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        return trim($m[1]);
    }

    if (isset($_GET['api_key']) && $_GET['api_key'] !== '') {
        return $_GET['api_key'];
    }

    return null;
}
