<?php
/**
 * Alerts Engine
 *
 * Processes and delivers alerts based on tracking events.
 */

class AlertsEngine
{
    private AlertsRepo $alertsRepo;
    private EventsRepo $eventsRepo;

    public function __construct(AlertsRepo $alertsRepo, EventsRepo $eventsRepo)
    {
        $this->alertsRepo = $alertsRepo;
        $this->eventsRepo = $eventsRepo;
    }

    /**
     * Trigger a geofence alert.
     *
     * @param int $familyId
     * @param int $userId Who triggered it
     * @param int $geofenceId
     * @param string $geofenceName
     * @param string $action 'enter' or 'exit'
     * @return bool Whether alert was sent
     */
    public function triggerGeofenceAlert(
        int $familyId,
        int $userId,
        int $geofenceId,
        string $geofenceName,
        string $action
    ): bool {
        $ruleType = $action === 'enter'
            ? AlertsRepo::RULE_ENTER_GEOFENCE
            : AlertsRepo::RULE_EXIT_GEOFENCE;

        return $this->trigger($familyId, $userId, $ruleType, $geofenceId, $geofenceName);
    }

    /**
     * Trigger a place alert.
     *
     * @param int $familyId
     * @param int $userId Who triggered it
     * @param int $placeId
     * @param string $placeLabel
     * @param string $action 'arrive' or 'leave'
     * @return bool Whether alert was sent
     */
    public function triggerPlaceAlert(
        int $familyId,
        int $userId,
        int $placeId,
        string $placeLabel,
        string $action
    ): bool {
        $ruleType = $action === 'arrive'
            ? AlertsRepo::RULE_ARRIVE_PLACE
            : AlertsRepo::RULE_LEAVE_PLACE;

        return $this->trigger($familyId, $userId, $ruleType, $placeId, $placeLabel);
    }

    /**
     * Generic trigger method.
     */
    private function trigger(
        int $familyId,
        int $userId,
        string $ruleType,
        int $targetId,
        string $targetName
    ): bool {
        // Check if rule is enabled
        if (!$this->alertsRepo->isRuleEnabled($familyId, $ruleType)) {
            return false;
        }

        // Check cooldown
        if ($this->alertsRepo->isInCooldown($familyId, $ruleType, $userId, $targetId)) {
            return false;
        }

        // Record delivery
        $this->alertsRepo->recordDelivery($familyId, $ruleType, $userId, $targetId, 'inapp');

        // Log alert triggered event
        $this->eventsRepo->logAlertTriggered($familyId, $userId, $ruleType, $targetId, $targetName);

        // TODO: Future - push notifications, SMS, email
        // $this->sendPushNotification($familyId, $userId, $ruleType, $targetName);

        return true;
    }

    /**
     * Get pending alerts for a family (unread in-app alerts).
     * For now, this returns recent alert events.
     */
    public function getPendingAlerts(int $familyId, int $limit = 10): array
    {
        return $this->eventsRepo->getList($familyId, [
            'limit' => $limit,
            'event_types' => [EventsRepo::TYPE_ALERT_TRIGGERED],
            'start_time' => Time::subSeconds(86400) // Last 24 hours
        ]);
    }

    /**
     * Get alert summary for dashboard.
     */
    public function getSummary(int $familyId): array
    {
        $rules = $this->alertsRepo->getRules($familyId);
        $deliveries = $this->alertsRepo->getDeliveries($familyId, [
            'limit' => 20,
            'start_time' => Time::subSeconds(86400)
        ]);

        return [
            'enabled' => $rules['enabled'],
            'rules' => [
                'arrive_place' => $rules['arrive_place_enabled'],
                'leave_place' => $rules['leave_place_enabled'],
                'enter_geofence' => $rules['enter_geofence_enabled'],
                'exit_geofence' => $rules['exit_geofence_enabled']
            ],
            'cooldown_seconds' => $rules['cooldown_seconds'],
            'quiet_hours' => [
                'start' => $rules['quiet_hours_start'],
                'end' => $rules['quiet_hours_end']
            ],
            'recent_alerts_24h' => count($deliveries),
            'recent_alerts' => array_slice($deliveries, 0, 5)
        ];
    }
}
