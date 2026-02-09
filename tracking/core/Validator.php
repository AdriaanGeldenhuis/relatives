<?php
declare(strict_types=1);

/**
 * Input validation for tracking payloads
 */
class TrackingValidator
{
    private array $errors = [];

    /**
     * Validate and normalize a location payload.
     * Supports old field names for backward compatibility.
     */
    public function validateLocation(array $input): ?array
    {
        $lat = $input['lat'] ?? $input['latitude'] ?? null;
        $lng = $input['lng'] ?? $input['longitude'] ?? $input['lon'] ?? null;

        if ($lat === null || $lng === null) {
            $this->errors[] = 'lat and lng are required';
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if ($lat < -90 || $lat > 90) {
            $this->errors[] = 'lat must be between -90 and 90';
            return null;
        }
        if ($lng < -180 || $lng > 180) {
            $this->errors[] = 'lng must be between -180 and 180';
            return null;
        }

        // Speed: accept m/s or km/h
        $speedMps = null;
        if (isset($input['speed_mps'])) {
            $speedMps = (float) $input['speed_mps'];
        } elseif (isset($input['speed_kmh'])) {
            $speedMps = (float) $input['speed_kmh'] / 3.6;
        } elseif (isset($input['speed'])) {
            $speedMps = (float) $input['speed'];
        }

        $accuracyM = $input['accuracy_m'] ?? $input['accuracy'] ?? null;
        if ($accuracyM !== null) {
            $accuracyM = (float) $accuracyM;
        }

        $bearingDeg = $input['bearing_deg'] ?? $input['bearing'] ?? $input['heading'] ?? null;
        if ($bearingDeg !== null) {
            $bearingDeg = fmod((float) $bearingDeg + 360, 360);
        }

        $altitudeM = $input['altitude_m'] ?? $input['altitude'] ?? null;
        if ($altitudeM !== null) {
            $altitudeM = (float) $altitudeM;
        }

        $recordedAt = $input['recorded_at'] ?? $input['timestamp'] ?? null;
        if ($recordedAt) {
            // Treat input as UTC — append UTC if no timezone info present
            // Check for timezone markers at end of string (Z, +HH:MM, -HH:MM, UTC, GMT)
            // Previous regex falsely matched date hyphens like "-02" in "2026-02-09"
            $normalized = $recordedAt;
            if (!preg_match('/(?:Z|[+\-]\d{2}:?\d{2}|UTC|GMT)\s*$/i', $recordedAt)) {
                $normalized .= ' UTC';
            }
            $ts = strtotime($normalized);
            $recordedAt = $ts ? gmdate('Y-m-d H:i:s', $ts) : gmdate('Y-m-d H:i:s');
        } else {
            $recordedAt = gmdate('Y-m-d H:i:s');
        }

        return [
            'lat' => $lat,
            'lng' => $lng,
            'accuracy_m' => $accuracyM,
            'speed_mps' => $speedMps,
            'bearing_deg' => $bearingDeg,
            'altitude_m' => $altitudeM,
            'recorded_at' => $recordedAt,
            'device_id' => $input['device_id'] ?? $input['device_uuid'] ?? null,
            'platform' => $input['platform'] ?? null,
            'app_version' => $input['app_version'] ?? null,
            'battery_level' => isset($input['battery_level']) ? (int) $input['battery_level'] : null,
            'is_moving' => isset($input['is_moving']) ? (bool) $input['is_moving'] : null,
        ];
    }

    /**
     * Validate a geofence creation payload
     */
    public function validateGeofence(array $input): ?array
    {
        $name = trim($input['name'] ?? '');
        if (empty($name) || strlen($name) > 100) {
            $this->errors[] = 'name is required (max 100 chars)';
            return null;
        }

        $type = $input['type'] ?? 'circle';
        if (!in_array($type, ['circle', 'polygon'], true)) {
            $this->errors[] = 'type must be circle or polygon';
            return null;
        }

        if ($type === 'circle') {
            $cLat = $input['center_lat'] ?? $input['lat'] ?? null;
            $cLng = $input['center_lng'] ?? $input['lng'] ?? null;
            $radius = $input['radius_m'] ?? $input['radius'] ?? null;

            if ($cLat === null || $cLng === null || $radius === null) {
                $this->errors[] = 'circle requires center_lat, center_lng, radius_m';
                return null;
            }

            return [
                'name' => $name,
                'type' => 'circle',
                'center_lat' => (float) $cLat,
                'center_lng' => (float) $cLng,
                'radius_m' => max(50, min(50000, (int) $radius)),
                'polygon_json' => null,
            ];
        }

        // polygon
        $points = $input['polygon'] ?? $input['polygon_json'] ?? null;
        if (is_string($points)) {
            $points = json_decode($points, true);
        }
        if (!is_array($points) || count($points) < 3) {
            $this->errors[] = 'polygon requires at least 3 points';
            return null;
        }

        return [
            'name' => $name,
            'type' => 'polygon',
            'center_lat' => null,
            'center_lng' => null,
            'radius_m' => null,
            'polygon_json' => json_encode($points),
        ];
    }

    /**
     * Validate a place creation payload
     */
    public function validatePlace(array $input): ?array
    {
        $label = trim($input['label'] ?? $input['name'] ?? '');
        if (empty($label) || strlen($label) > 100) {
            $this->errors[] = 'label is required (max 100 chars)';
            return null;
        }

        $lat = $input['lat'] ?? $input['latitude'] ?? null;
        $lng = $input['lng'] ?? $input['longitude'] ?? null;
        if ($lat === null || $lng === null) {
            $this->errors[] = 'lat and lng are required';
            return null;
        }

        $category = $input['category'] ?? 'other';
        if (!in_array($category, ['home', 'work', 'school', 'other'], true)) {
            $category = 'other';
        }

        return [
            'label' => $label,
            'category' => $category,
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'radius_m' => max(50, min(5000, (int) ($input['radius_m'] ?? 100))),
            'address' => $input['address'] ?? null,
        ];
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
}
