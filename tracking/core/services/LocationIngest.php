<?php
declare(strict_types=1);

final class LocationIngest
{
    public function __construct(private PDO $db, private TrackingCache $cache) {}

    public function process(SiteContext $ctx, array $inputs): array
    {
        $db = $this->db;
        $settingsRepo = new SettingsRepo($db, $this->cache);
        $settingsRepo->get($ctx->familyId);
        $repo = new LocationRepo($db, $this->cache);
        $preciseTime = TrackingPolicy::hasColumn($db, 'tracking_current', 'recorded_ms');
        $quality = TrackingPolicy::locationQuality($db);
        $receipts = TrackingPolicy::hasTable($db, 'tracking_fix_receipts');
        $wake = TrackingPolicy::confirmedWake($db) ? new TrackingWake($db) : null;
        $geofences = new GeofenceEngine(
            $db, new GeofenceRepo($db, $this->cache), new PlacesRepo($db, $this->cache),
            new EventsRepo($db), new AlertsEngine($db, new AlertsRepo($db, $this->cache))
        );

        $pending = [];
        $results = [];
        foreach ($inputs as $index => $input) {
            $event = is_array($input) && is_string($input['client_event_id'] ?? null) ? $input['client_event_id'] : null;
            $results[$index] = ['index' => $index, 'client_event_id' => $event];
            $validator = new TrackingValidator();
            $fix = is_array($input) ? $validator->validateLocation($input) : null;
            if ($fix === null) {
                $results[$index] += ['status' => 'error', 'error' => $validator->getErrors()[0] ?? 'invalid_location'];
            } elseif ($fix['accuracy_m'] !== null && $fix['accuracy_m'] > 2000) {
                $results[$index] += ['status' => 'skipped', 'reason' => 'accuracy_too_low'];
            } else {
                $pending[$index] = $fix;
            }
        }
        uasort($pending, static fn(array $a, array $b): int => $a['recorded_ms'] <=> $b['recorded_ms']);
        $stored = 0;
        $commitStart = Time::nowMs();
        $wakeFixes = 0;
        $held = 0;
        $wakeDevice = null;
        $approximate = 0;
        $oldestMs = 0;
        $db->beginTransaction();
        try {
            $settings = $settingsRepo->lockForTracking($ctx->familyId);
            $access = $ctx->lockForLocationWrite($db);
            if ($access !== 'allowed' || (int) $settings['mode'] === 0) {
                $db->rollBack();
                return ['blocked' => $access === 'allowed' ? 'tracking_disabled' : $access];
            }
            $policy = TrackingPolicy::native($settings, true);
            $cutoff = TrackingPolicy::cutoff($db, $ctx->familyId, $ctx->userId);
            $current = $repo->currentForWrite($ctx->userId, $ctx->familyId, $preciseTime, $quality);
            $motion = new MotionGate((float) $settings['speed_threshold_mps'], (float) $settings['distance_threshold_m']);
            $previous = null;
            foreach ($pending as $index => $fix) {
                if ($fix['platform'] === 'android' && TrackingWake::isId($fix['device_id'])) {
                    $wakeDevice = $fix['device_id'];
                }
                $key = $receipts && $fix['device_id'] !== null && $fix['client_event_id'] !== null
                    ? [$ctx->userId, $fix['device_id'], $fix['client_event_id']] : null;
                $reply = $key === null ? null : $this->previousReceipt($key);
                if ($reply !== null) {
                    if ($reply['status'] === 'stored') {
                        $reply['status'] = 'duplicate';
                    }
                    $results[$index] += $reply;
                    continue;
                }

                if ($fix['recorded_ms'] < $cutoff || $fix['recorded_ms'] < Time::nowMs() - 7 * 86400000) {
                    $reply = ['status' => 'skipped', 'reason' => 'expired_fix'];
                } elseif ($fix['wake_request_id'] !== null && !($wake?->acceptsFix($ctx, $fix) ?? false)) {
                    $reply = ['status' => 'skipped', 'reason' => 'wake_expired_or_unavailable'];
                } else {
                    $currentMs = LocationQuality::capture($current);
                    $fixNow = Time::nowMs();
                    $reliable = LocationQuality::reliable($fix, $policy);
                    $approximate += (int) !$reliable;
                    $oldestMs = max($oldestMs, $fixNow - $fix['recorded_ms']);
                    $selection = $quality ? LocationQuality::selection($fix, $current, $policy, $fixNow)
                        : ($fix['recorded_ms'] > $currentMs ? 'advance' : 'out_of_order');
                    $newer = $selection === 'advance';
                    $sameSource = !$current || LocationQuality::sameSource($current, $fix);
                    $sameConsent = !$current || LocationQuality::sameConsent($current, $fix);
                    $baseline = $newer ? $current : $previous;
                    $evaluation = $motion->evaluate($fix, $baseline);
                    $fix['motion_state'] = $evaluation['motion_state'];
                    $history = $evaluation['store_history'] && !$this->nearDuplicate($fix, $baseline ?? $current, $settings)
                        && (!$quality || ($sameSource && $sameConsent && $reliable
                            && in_array($selection, ['advance', 'out_of_order'], true)));
                    if ($history) {
                        $repo->insertHistory($ctx->familyId, $ctx->userId, $fix, $evaluation['motion_state']);
                    }
                    if ($newer) {
                        $repo->upsertCurrent($ctx->userId, $ctx->familyId, $fix, $fix['motion_state'], $preciseTime, $quality);
                        if ($quality && (!$sameSource || !$sameConsent)) $geofences->resetSource($ctx->familyId, $ctx->userId);
                        if ($wake !== null && TrackingWake::isId($fix['device_id'])) {
                            $wakeFixes += $wake->confirmObservation($ctx, $fix, $cutoff);
                        }
                    } elseif ($selection === 'hold_coarse' || $selection === 'hold_jump') {
                        $current['pending_fix_json'] = $repo->holdCandidate($ctx->userId, $ctx->familyId,
                            LocationQuality::held($fix, $current, $selection, $fixNow));
                        $held++;
                    }
                    if (($newer || ($quality && $sameSource && $sameConsent && $selection === 'out_of_order'))
                        && isset($fix['accuracy_m']) && $fix['accuracy_m'] > 0 && $fix['accuracy_m'] <= $policy['min_accuracy_m']) {
                        $geofences->observe($ctx->familyId, $ctx->userId, $ctx->name, $fix, $cutoff, $newer);
                    }
                    if ($newer) {
                        $current = $fix;
                    }
                    if (!$quality || $sameSource) {
                        $previous = $fix;
                    }
                    $stored++;
                    $reply = [
                        'status' => 'stored', 'motion_state' => $evaluation['motion_state'], 'stored_history' => $history,
                        'advanced_current' => $newer, 'selection' => $selection,
                    ];
                }
                if ($key !== null) {
                    $db->prepare('INSERT INTO tracking_fix_receipts (user_id, device_id, client_event_id, result_json)
                        VALUES (?, ?, ?, ?)')->execute([...$key, json_encode($reply, JSON_THROW_ON_ERROR)]);
                }
                $results[$index] += $reply;
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        TrackingPolicy::metric('location_batch', [
            'duration_ms' => Time::nowMs() - $commitStart, 'processed' => count($inputs), 'stored' => $stored,
            'wake_fixes' => $wakeFixes, 'held_fixes' => $held, 'approximate_fixes' => $approximate, 'oldest_capture_age_ms' => $oldestMs,
        ]);
        return [
            'processed' => count($inputs), 'stored' => $stored,
            'results' => array_values($results), 'server_settings' => $policy,
            'pending_wake' => $wakeDevice && !$wakeFixes ? $wake?->pending($ctx, $wakeDevice) : null,
        ];
    }

    private function previousReceipt(array $key): ?array
    {
        $stmt = $this->db->prepare('SELECT result_json FROM tracking_fix_receipts
            WHERE user_id = ? AND device_id = ? AND client_event_id = ?');
        $stmt->execute($key);
        $json = $stmt->fetchColumn();
        return $json ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    private function nearDuplicate(array $fix, ?array $previous, array $settings): bool
    {
        return $previous !== null
            && abs($fix['recorded_ms'] - (int) ($previous['recorded_ms'] ?? Time::captureMs($previous['recorded_at'])))
                < 1000 * (int) $settings['dedupe_time_seconds']
            && geo_haversineDistance((float) $previous['lat'], (float) $previous['lng'], $fix['lat'], $fix['lng'])
                < (int) $settings['dedupe_radius_m'];
    }

    public static function respond(array $batch, bool $single): void
    {
        if (isset($batch['blocked'])) {
            match ($batch['blocked']) {
                'not_authenticated' => Response::error('not_authenticated', 401),
                'location_sharing_disabled' => Response::error('location_sharing_disabled', 403),
                default => Response::success(
                    $single ? ['status' => 'tracking_disabled', 'server_settings' => ['mode' => 0]]
                        : ['processed' => 0, 'stored' => 0, 'results' => [], 'server_settings' => ['mode' => 0]],
                    'tracking_disabled'
                ),
            };
        }
        if ($single) {
            $result = $batch['results'][0];
            if ($result['status'] === 'error' || $result['status'] === 'skipped') {
                Response::error($result['error'] ?? $result['reason'], 422);
            }
            Response::success($result + ['server_settings' => $batch['server_settings'], 'pending_wake' => $batch['pending_wake']]);
        }
        Response::success($batch);
    }
}
