<?php
declare(strict_types=1);

function weatherTrackedLocation(PDO $db, int $userId, int $familyId): ?array
{
    $now = time();
    $sources = [
        ['tracking_current', 'updated_at', 't.user_id'],
        ['tracking_locations', 'created_at', 't.id DESC'],
    ];

    foreach ($sources as [$table, $receivedColumn, $order]) {
        try {
            // History still has two schemas in use. Read both coordinate and timestamp formats below.
            $stmt = $db->prepare("SELECT t.*, UNIX_TIMESTAMP(t.$receivedColumn) AS received_at
                FROM $table t JOIN users u ON u.id = t.user_id AND u.family_id = t.family_id
                WHERE t.user_id = ? AND t.family_id = ? AND u.location_sharing = 1
                ORDER BY $order LIMIT 1");
            $stmt->execute([$userId, $familyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Weather location ($table): " . $e->getMessage());
            continue;
        }

        if (!$row) {
            continue;
        }
        $recorded = $row['recorded_at'] ?? $row['client_timestamp'] ?? null;
        $fixedAt = $recorded ? strtotime($recorded . ' UTC') : (int)$row['received_at'];
        $lat = $row['lat'] ?? $row['latitude'] ?? null;
        $lon = $row['lng'] ?? $row['longitude'] ?? null;
        if ($fixedAt !== false && $fixedAt >= $now - 1800 && $fixedAt <= $now && is_numeric($lat) && is_numeric($lon)
            && abs((float)$lat) <= 90 && abs((float)$lon) <= 180) {
            return ['lat' => round((float)$lat, 2), 'lon' => round((float)$lon, 2)];
        }
    }

    return null;
}

function weatherSavedCity(PDO $db, int $userId): ?array
{
    try {
        $stmt = $db->prepare('SELECT name, lat, lon FROM weather_saved_cities WHERE user_id = ?');
        $stmt->execute([$userId]);
        $city = $stmt->fetch(PDO::FETCH_ASSOC);
        return $city ? ['name' => $city['name'], 'lat' => (float) $city['lat'], 'lon' => (float) $city['lon']] : null;
    } catch (PDOException $error) {
        if ($error->getCode() !== '42S02') {
            throw $error;
        }
        return null;
    }
}

function weatherLocationForUser(PDO $db, int $userId, int $familyId): array
{
    return weatherTrackedLocation($db, $userId, $familyId)
        ?? weatherSavedCity($db, $userId)
        ?? ['lat' => -26.2041, 'lon' => 28.0473, 'name' => 'Johannesburg'];
}
