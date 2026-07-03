<?php
/**
 * Funciones geográficas auxiliares.
 */

/**
 * Distancia en metros entre dos puntos GPS usando la fórmula de Haversine.
 */
function haversineMetros(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $radioTierra = 6371000; // metros

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $radioTierra * $c;
}
