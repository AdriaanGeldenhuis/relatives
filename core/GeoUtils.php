<?php
declare(strict_types=1);

/**
 * GeoUtils - Shared geographic utility functions
 *
 * Single source of truth for distance calculations used across:
 * - tracking/api/get_location_history.php (stop detection)
 * - tracking/jobs/process_geofences.php (zone checking)
 * - core/tracking/FixQualityGate.php (drift/teleport detection)
 */

/**
 * Calculate distance between two points using the Haversine formula
 *
 * @param float $lat1 Latitude of point 1
 * @param float $lon1 Longitude of point 1
 * @param float $lat2 Latitude of point 2
 * @param float $lon2 Longitude of point 2
 * @return float Distance in meters
 */
function geo_haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadius = 6371000; // meters

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

/**
 * Check if a point is inside a polygon (ray-casting algorithm)
 *
 * @param float $lat Latitude of the point
 * @param float $lng Longitude of the point
 * @param array $polygon Array of ['lat' => float, 'lng' => float] points
 * @return bool True if point is inside polygon
 */
function geo_isPointInPolygon(float $lat, float $lng, array $polygon): bool {
    $inside = false;
    $count = count($polygon);

    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $xi = $polygon[$i]['lat'];
        $yi = $polygon[$i]['lng'];
        $xj = $polygon[$j]['lat'];
        $yj = $polygon[$j]['lng'];

        $intersect = (($yi > $lng) != ($yj > $lng))
            && ($lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi);

        if ($intersect) {
            $inside = !$inside;
        }
    }

    return $inside;
}
