<?php
declare(strict_types=1);

class TrackingValidator {
    /**
     * Validate location coordinates
     */
    public static function coordinates(array $data): array {
        $errors = [];

        $lat = $data['lat'] ?? $data['latitude'] ?? null;
        $lng = $data['lng'] ?? $data['longitude'] ?? null;

        if ($lat === null || $lng === null) {
            $errors[] = 'lat and lng are required';
        } else {
            $lat = (float)$lat;
            $lng = (float)$lng;

            if ($lat < -90 || $lat > 90) $errors[] = 'lat must be between -90 and 90';
            if ($lng < -180 || $lng > 180) $errors[] = 'lng must be between -180 and 180';
            if ($lat == 0 && $lng == 0) $errors[] = 'coordinates cannot be 0,0';
        }

        return $errors;
    }

    /**
     * Validate and sanitize a location update payload
     */
    public static function locationUpdate(array $data): array {
        return [
            'lat' => (float)($data['lat'] ?? $data['latitude'] ?? 0),
            'lng' => (float)($data['lng'] ?? $data['longitude'] ?? 0),
            'accuracy' => (float)($data['accuracy'] ?? $data['accuracy_m'] ?? 0),
            'altitude' => isset($data['altitude']) ? (float)$data['altitude'] : null,
            'speed' => (float)($data['speed'] ?? 0),
            'heading' => isset($data['heading']) ? (float)$data['heading'] : null,
            'battery' => (int)($data['battery'] ?? 0),
            'is_moving' => (bool)($data['is_moving'] ?? $data['moving'] ?? false),
            'source' => in_array($data['source'] ?? '', ['gps', 'network', 'fused', 'passive']) ? $data['source'] : 'fused',
        ];
    }

    /**
     * Validate geofence data
     */
    public static function geofence(array $data): array {
        $errors = [];

        if (empty($data['name'])) $errors[] = 'name is required';
        if (strlen($data['name'] ?? '') > 100) $errors[] = 'name must be 100 chars or less';

        $type = $data['type'] ?? 'circle';
        if (!in_array($type, ['circle', 'polygon'])) $errors[] = 'type must be circle or polygon';

        if ($type === 'circle') {
            $coordErrors = self::coordinates($data);
            $errors = array_merge($errors, $coordErrors);

            $radius = (int)($data['radius_m'] ?? $data['radius'] ?? 0);
            if ($radius < 50 || $radius > 50000) $errors[] = 'radius must be between 50 and 50000 meters';
        }

        return $errors;
    }

    /**
     * Validate a string with max length
     */
    public static function string(?string $value, int $maxLength = 255): ?string {
        if ($value === null || trim($value) === '') return null;
        return mb_substr(trim($value), 0, $maxLength);
    }

    /**
     * Validate a positive integer
     */
    public static function positiveInt($value): ?int {
        $val = filter_var($value, FILTER_VALIDATE_INT);
        return ($val !== false && $val > 0) ? $val : null;
    }
}
