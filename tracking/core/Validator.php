<?php
/**
 * Tracking Validator
 *
 * Input validation for tracking data.
 */

class TrackingValidator
{
    /**
     * Validate a single location payload.
     *
     * @param array $data Input data
     * @return array ['valid' => bool, 'errors' => [], 'cleaned' => []]
     */
    public static function validateLocation(array $data): array
    {
        $errors = [];
        $cleaned = [];

        // Required: lat
        if (!isset($data['lat'])) {
            $errors[] = 'lat is required';
        } else {
            $lat = filter_var($data['lat'], FILTER_VALIDATE_FLOAT);
            if ($lat === false || $lat < -90 || $lat > 90) {
                $errors[] = 'lat must be between -90 and 90';
            } else {
                $cleaned['lat'] = round($lat, 7);
            }
        }

        // Required: lng
        if (!isset($data['lng'])) {
            $errors[] = 'lng is required';
        } else {
            $lng = filter_var($data['lng'], FILTER_VALIDATE_FLOAT);
            if ($lng === false || $lng < -180 || $lng > 180) {
                $errors[] = 'lng must be between -180 and 180';
            } else {
                $cleaned['lng'] = round($lng, 7);
            }
        }

        // Optional: accuracy_m
        if (isset($data['accuracy_m'])) {
            $accuracy = filter_var($data['accuracy_m'], FILTER_VALIDATE_FLOAT);
            if ($accuracy !== false && $accuracy >= 0 && $accuracy <= 10000) {
                $cleaned['accuracy_m'] = round($accuracy, 2);
            }
        }

        // Optional: speed_mps
        if (isset($data['speed_mps'])) {
            $speed = filter_var($data['speed_mps'], FILTER_VALIDATE_FLOAT);
            if ($speed !== false && $speed >= 0 && $speed <= 1000) {
                $cleaned['speed_mps'] = round($speed, 2);
            }
        }

        // Optional: bearing_deg
        if (isset($data['bearing_deg'])) {
            $bearing = filter_var($data['bearing_deg'], FILTER_VALIDATE_FLOAT);
            if ($bearing !== false && $bearing >= 0 && $bearing <= 360) {
                $cleaned['bearing_deg'] = round($bearing, 2);
            }
        }

        // Optional: altitude_m
        if (isset($data['altitude_m'])) {
            $altitude = filter_var($data['altitude_m'], FILTER_VALIDATE_FLOAT);
            if ($altitude !== false && $altitude >= -1000 && $altitude <= 50000) {
                $cleaned['altitude_m'] = round($altitude, 2);
            }
        }

        // Optional: recorded_at (ISO 8601 or Unix timestamp)
        if (isset($data['recorded_at'])) {
            $timestamp = self::parseTimestamp($data['recorded_at']);
            if ($timestamp) {
                $cleaned['recorded_at'] = $timestamp;
            }
        } else {
            $cleaned['recorded_at'] = date('Y-m-d H:i:s');
        }

        // Optional: device info
        if (isset($data['device_id']) && is_string($data['device_id'])) {
            $cleaned['device_id'] = substr($data['device_id'], 0, 64);
        }

        if (isset($data['platform']) && is_string($data['platform'])) {
            $cleaned['platform'] = substr(strtolower($data['platform']), 0, 20);
        }

        if (isset($data['app_version']) && is_string($data['app_version'])) {
            $cleaned['app_version'] = substr($data['app_version'], 0, 20);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'cleaned' => $cleaned
        ];
    }

    /**
     * Validate batch of locations.
     */
    public static function validateBatch(array $locations): array
    {
        if (!is_array($locations)) {
            return [
                'valid' => false,
                'errors' => ['locations must be an array'],
                'cleaned' => []
            ];
        }

        if (count($locations) > 100) {
            return [
                'valid' => false,
                'errors' => ['Maximum 100 locations per batch'],
                'cleaned' => []
            ];
        }

        if (empty($locations)) {
            return [
                'valid' => false,
                'errors' => ['At least one location required'],
                'cleaned' => []
            ];
        }

        $allErrors = [];
        $cleanedLocations = [];

        foreach ($locations as $index => $location) {
            $result = self::validateLocation($location);
            if (!$result['valid']) {
                $allErrors[] = "Location {$index}: " . implode(', ', $result['errors']);
            } else {
                $cleanedLocations[] = $result['cleaned'];
            }
        }

        return [
            'valid' => empty($allErrors),
            'errors' => $allErrors,
            'cleaned' => $cleanedLocations
        ];
    }

    /**
     * Validate geofence data.
     */
    public static function validateGeofence(array $data, bool $isUpdate = false): array
    {
        $errors = [];
        $cleaned = [];

        // Name (required for create)
        if (!$isUpdate || isset($data['name'])) {
            if (!isset($data['name']) || !is_string($data['name'])) {
                if (!$isUpdate) {
                    $errors[] = 'name is required';
                }
            } else {
                $name = trim($data['name']);
                if (strlen($name) < 1 || strlen($name) > 100) {
                    $errors[] = 'name must be 1-100 characters';
                } else {
                    $cleaned['name'] = $name;
                }
            }
        }

        // Type
        if (isset($data['type'])) {
            if (!in_array($data['type'], ['circle', 'polygon'])) {
                $errors[] = 'type must be circle or polygon';
            } else {
                $cleaned['type'] = $data['type'];
            }
        } elseif (!$isUpdate) {
            $cleaned['type'] = 'circle';
        }

        // Circle: center_lat, center_lng, radius_m
        $type = $cleaned['type'] ?? 'circle';
        if ($type === 'circle') {
            // center_lat
            if (!$isUpdate || isset($data['center_lat'])) {
                if (!isset($data['center_lat'])) {
                    if (!$isUpdate) {
                        $errors[] = 'center_lat is required for circle';
                    }
                } else {
                    $lat = filter_var($data['center_lat'], FILTER_VALIDATE_FLOAT);
                    if ($lat === false || $lat < -90 || $lat > 90) {
                        $errors[] = 'center_lat must be between -90 and 90';
                    } else {
                        $cleaned['center_lat'] = round($lat, 7);
                    }
                }
            }

            // center_lng
            if (!$isUpdate || isset($data['center_lng'])) {
                if (!isset($data['center_lng'])) {
                    if (!$isUpdate) {
                        $errors[] = 'center_lng is required for circle';
                    }
                } else {
                    $lng = filter_var($data['center_lng'], FILTER_VALIDATE_FLOAT);
                    if ($lng === false || $lng < -180 || $lng > 180) {
                        $errors[] = 'center_lng must be between -180 and 180';
                    } else {
                        $cleaned['center_lng'] = round($lng, 7);
                    }
                }
            }

            // radius_m
            if (!$isUpdate || isset($data['radius_m'])) {
                if (!isset($data['radius_m'])) {
                    if (!$isUpdate) {
                        $cleaned['radius_m'] = 100; // default
                    }
                } else {
                    $radius = filter_var($data['radius_m'], FILTER_VALIDATE_INT);
                    if ($radius === false || $radius < 10 || $radius > 100000) {
                        $errors[] = 'radius_m must be between 10 and 100000';
                    } else {
                        $cleaned['radius_m'] = $radius;
                    }
                }
            }
        }

        // Active flag
        if (isset($data['active'])) {
            $cleaned['active'] = $data['active'] ? 1 : 0;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'cleaned' => $cleaned
        ];
    }

    /**
     * Validate place data.
     */
    public static function validatePlace(array $data): array
    {
        $errors = [];
        $cleaned = [];

        // Label (required)
        if (!isset($data['label']) || !is_string($data['label'])) {
            $errors[] = 'label is required';
        } else {
            $label = trim($data['label']);
            if (strlen($label) < 1 || strlen($label) > 100) {
                $errors[] = 'label must be 1-100 characters';
            } else {
                $cleaned['label'] = $label;
            }
        }

        // Category
        $validCategories = ['home', 'work', 'school', 'other'];
        if (isset($data['category'])) {
            if (!in_array($data['category'], $validCategories)) {
                $errors[] = 'category must be: ' . implode(', ', $validCategories);
            } else {
                $cleaned['category'] = $data['category'];
            }
        } else {
            $cleaned['category'] = 'other';
        }

        // lat (required)
        if (!isset($data['lat'])) {
            $errors[] = 'lat is required';
        } else {
            $lat = filter_var($data['lat'], FILTER_VALIDATE_FLOAT);
            if ($lat === false || $lat < -90 || $lat > 90) {
                $errors[] = 'lat must be between -90 and 90';
            } else {
                $cleaned['lat'] = round($lat, 7);
            }
        }

        // lng (required)
        if (!isset($data['lng'])) {
            $errors[] = 'lng is required';
        } else {
            $lng = filter_var($data['lng'], FILTER_VALIDATE_FLOAT);
            if ($lng === false || $lng < -180 || $lng > 180) {
                $errors[] = 'lng must be between -180 and 180';
            } else {
                $cleaned['lng'] = round($lng, 7);
            }
        }

        // radius_m (optional)
        if (isset($data['radius_m'])) {
            $radius = filter_var($data['radius_m'], FILTER_VALIDATE_INT);
            if ($radius !== false && $radius >= 10 && $radius <= 10000) {
                $cleaned['radius_m'] = $radius;
            }
        } else {
            $cleaned['radius_m'] = 100;
        }

        // address (optional)
        if (isset($data['address']) && is_string($data['address'])) {
            $cleaned['address'] = substr(trim($data['address']), 0, 255);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'cleaned' => $cleaned
        ];
    }

    /**
     * Validate settings data.
     */
    public static function validateSettings(array $data): array
    {
        $cleaned = [];

        // Mode
        if (isset($data['mode'])) {
            $mode = filter_var($data['mode'], FILTER_VALIDATE_INT);
            if ($mode !== false && in_array($mode, [1, 2])) {
                $cleaned['mode'] = $mode;
            }
        }

        // Session TTL
        if (isset($data['session_ttl_seconds'])) {
            $ttl = filter_var($data['session_ttl_seconds'], FILTER_VALIDATE_INT);
            if ($ttl !== false && $ttl >= 60 && $ttl <= 3600) {
                $cleaned['session_ttl_seconds'] = $ttl;
            }
        }

        // Intervals
        $intervalFields = ['keepalive_interval_seconds', 'moving_interval_seconds', 'idle_interval_seconds'];
        foreach ($intervalFields as $field) {
            if (isset($data[$field])) {
                $val = filter_var($data[$field], FILTER_VALIDATE_INT);
                if ($val !== false && $val >= 5 && $val <= 3600) {
                    $cleaned[$field] = $val;
                }
            }
        }

        // Thresholds
        if (isset($data['speed_threshold_mps'])) {
            $val = filter_var($data['speed_threshold_mps'], FILTER_VALIDATE_FLOAT);
            if ($val !== false && $val >= 0 && $val <= 100) {
                $cleaned['speed_threshold_mps'] = round($val, 2);
            }
        }

        if (isset($data['distance_threshold_m'])) {
            $val = filter_var($data['distance_threshold_m'], FILTER_VALIDATE_INT);
            if ($val !== false && $val >= 1 && $val <= 10000) {
                $cleaned['distance_threshold_m'] = $val;
            }
        }

        // Quality
        if (isset($data['min_accuracy_m'])) {
            $val = filter_var($data['min_accuracy_m'], FILTER_VALIDATE_INT);
            if ($val !== false && $val >= 1 && $val <= 10000) {
                $cleaned['min_accuracy_m'] = $val;
            }
        }

        if (isset($data['dedupe_radius_m'])) {
            $val = filter_var($data['dedupe_radius_m'], FILTER_VALIDATE_INT);
            if ($val !== false && $val >= 0 && $val <= 1000) {
                $cleaned['dedupe_radius_m'] = $val;
            }
        }

        if (isset($data['dedupe_time_seconds'])) {
            $val = filter_var($data['dedupe_time_seconds'], FILTER_VALIDATE_INT);
            if ($val !== false && $val >= 0 && $val <= 3600) {
                $cleaned['dedupe_time_seconds'] = $val;
            }
        }

        if (isset($data['rate_limit_seconds'])) {
            $val = filter_var($data['rate_limit_seconds'], FILTER_VALIDATE_INT);
            if ($val !== false && $val >= 0 && $val <= 300) {
                $cleaned['rate_limit_seconds'] = $val;
            }
        }

        // Display
        if (isset($data['units'])) {
            if (in_array($data['units'], ['metric', 'imperial'])) {
                $cleaned['units'] = $data['units'];
            }
        }

        if (isset($data['map_style'])) {
            $cleaned['map_style'] = substr($data['map_style'], 0, 50);
        }

        // Retention
        if (isset($data['history_retention_days'])) {
            $val = filter_var($data['history_retention_days'], FILTER_VALIDATE_INT);
            if ($val !== false && $val >= 1 && $val <= 365) {
                $cleaned['history_retention_days'] = $val;
            }
        }

        if (isset($data['events_retention_days'])) {
            $val = filter_var($data['events_retention_days'], FILTER_VALIDATE_INT);
            if ($val !== false && $val >= 1 && $val <= 365) {
                $cleaned['events_retention_days'] = $val;
            }
        }

        return [
            'valid' => true, // Settings are always partially valid
            'errors' => [],
            'cleaned' => $cleaned
        ];
    }

    /**
     * Parse timestamp from various formats.
     */
    private static function parseTimestamp($value): ?string
    {
        if (is_numeric($value)) {
            // Unix timestamp
            $ts = (int)$value;
            if ($ts > 0 && $ts < 2147483647) {
                return date('Y-m-d H:i:s', $ts);
            }
        } elseif (is_string($value)) {
            // Try ISO 8601
            $dt = \DateTime::createFromFormat(\DateTime::ATOM, $value);
            if ($dt) {
                return $dt->format('Y-m-d H:i:s');
            }

            // Try MySQL format
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
            if ($dt) {
                return $value;
            }
        }

        return null;
    }
}
