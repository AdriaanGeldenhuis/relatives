<?php
/**
 * Geofence Engine
 *
 * Processes location updates for geofence enter/exit events.
 */

class GeofenceEngine
{
    private GeofenceRepo $geofenceRepo;
    private EventsRepo $eventsRepo;
    private AlertsEngine $alertsEngine;

    public function __construct(
        GeofenceRepo $geofenceRepo,
        EventsRepo $eventsRepo,
        AlertsEngine $alertsEngine
    ) {
        $this->geofenceRepo = $geofenceRepo;
        $this->eventsRepo = $eventsRepo;
        $this->alertsEngine = $alertsEngine;
    }

    /**
     * Process a location update for geofence events.
     *
     * @param int $familyId
     * @param int $userId
     * @param float $lat
     * @param float $lng
     * @return array List of triggered events
     */
    public function process(int $familyId, int $userId, float $lat, float $lng): array
    {
        $events = [];

        // Get active geofences
        $geofences = $this->geofenceRepo->getActive($familyId);

        if (empty($geofences)) {
            return $events;
        }

        // Get current state for user
        $currentState = $this->geofenceRepo->getUserState($userId);

        foreach ($geofences as $geofence) {
            $geofenceId = $geofence['id'];
            $wasInside = isset($currentState[$geofenceId]) && $currentState[$geofenceId]['is_inside'];
            $isNowInside = $this->geofenceRepo->isPointInside($lat, $lng, $geofence);

            // Update state and check for transition
            $result = $this->geofenceRepo->updateState($familyId, $geofenceId, $userId, $isNowInside);

            if ($result['entered']) {
                // Entered geofence
                $this->eventsRepo->logGeofenceEnter(
                    $familyId, $userId, $geofenceId, $geofence['name'], $lat, $lng
                );

                // Trigger alert
                $this->alertsEngine->triggerGeofenceAlert(
                    $familyId, $userId, $geofenceId, $geofence['name'], 'enter'
                );

                $events[] = [
                    'type' => 'enter_geofence',
                    'geofence_id' => $geofenceId,
                    'geofence_name' => $geofence['name']
                ];
            } elseif ($result['exited']) {
                // Exited geofence
                $this->eventsRepo->logGeofenceExit(
                    $familyId, $userId, $geofenceId, $geofence['name'], $lat, $lng
                );

                // Trigger alert
                $this->alertsEngine->triggerGeofenceAlert(
                    $familyId, $userId, $geofenceId, $geofence['name'], 'exit'
                );

                $events[] = [
                    'type' => 'exit_geofence',
                    'geofence_id' => $geofenceId,
                    'geofence_name' => $geofence['name']
                ];
            }
        }

        return $events;
    }

    /**
     * Get geofence status for a user (which geofences they're in).
     */
    public function getUserGeofenceStatus(int $familyId, int $userId): array
    {
        $geofences = $this->geofenceRepo->getActive($familyId);
        $state = $this->geofenceRepo->getUserState($userId);

        $status = [];
        foreach ($geofences as $geofence) {
            $geoState = $state[$geofence['id']] ?? ['is_inside' => false];
            $status[] = [
                'geofence_id' => $geofence['id'],
                'name' => $geofence['name'],
                'is_inside' => $geoState['is_inside'],
                'last_entered_at' => $geoState['last_entered_at'] ?? null,
                'last_exited_at' => $geoState['last_exited_at'] ?? null
            ];
        }

        return $status;
    }

    /**
     * Check which geofences a point is inside (without updating state).
     */
    public function checkPoint(int $familyId, float $lat, float $lng): array
    {
        $geofences = $this->geofenceRepo->getActive($familyId);
        $inside = [];

        foreach ($geofences as $geofence) {
            if ($this->geofenceRepo->isPointInside($lat, $lng, $geofence)) {
                $inside[] = [
                    'id' => $geofence['id'],
                    'name' => $geofence['name']
                ];
            }
        }

        return $inside;
    }
}
