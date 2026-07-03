<?php
/**
 * Middleware de autenticación por API key.
 * Implementación completa pendiente en M1 — por ahora bloquea todo acceso.
 */

function requireApiKey(): void
{
    jsonResponse(false, 'API key requerida', 401);
}
