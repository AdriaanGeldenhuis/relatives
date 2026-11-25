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

        if (!is_numeric($lat) || !is_numeric($lng)
            || !is_finite((float) $lat) || !is_finite((float) $lng)
            || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            $this->errors[] = 'invalid_coordinates';
            return null;
        }

        $recorded = $input['recorded_at_ms'] ?? $input['recorded_at'] ?? $input['timestamp'] ?? null;
        $recordedMs = $recorded === null ? Time::nowMs() : Time::captureMs($recorded);
        if ($recordedMs === null || $recordedMs <= 0) {
            $this->errors[] = 'invalid_capture_time';
            return null;
        }
        $recordedMs = min($recordedMs, Time::nowMs());

        $accuracy = $input['accuracy_m'] ?? $input['accuracy'] ?? null;
        if ($accuracy !== null && (!is_numeric($accuracy) || !is_finite((float) $accuracy) || (float) $accuracy < 0)) {
            $this->errors[] = 'invalid_accuracy';
            return null;
        }

        $speed = self::number($input['speed_mps'] ?? null)
            ?? (isset($input['speed_kmh']) && is_numeric($input['speed_kmh'])
                ? self::number((float) $input['speed_kmh'] / 3.6) : null)
            ?? self::number($input['speed'] ?? null);
        $bearing = self::number($input['bearing_deg'] ?? $input['bearing'] ?? $input['heading'] ?? null);
        $altitude = self::number($input['altitude_m'] ?? $input['altitude'] ?? null);
        $device = $input['device_id'] ?? $input['device_uuid'] ?? null;
        $event = $input['client_event_id'] ?? null;

        return [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'accuracy_m' => $accuracy === null ? null : (float) $accuracy,
            'speed_mps' => $speed !== null && $speed >= 0 && $speed < 100000 ? $speed : null,
            'bearing_deg' => $bearing === null ? null : fmod(fmod($bearing, 360) + 360, 360),
            'altitude_m' => $altitude !== null && abs($altitude) < 100000 ? $altitude : null,
            'recorded_ms' => $recordedMs,
            'recorded_at' => Time::format(intdiv($recordedMs, 1000)),
            'device_id' => self::identifier($device),
            'client_event_id' => self::identifier($event),
            'wake_request_id' => self::identifier($input['wake_request_id'] ?? null),
            'platform' => is_string($input['platform'] ?? null) ? substr($input['platform'], 0, 20) : null,
            'app_version' => is_string($input['app_version'] ?? null) ? substr($input['app_version'], 0, 20) : null,
        ];
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private static function identifier(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value) ? $value : null;
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
