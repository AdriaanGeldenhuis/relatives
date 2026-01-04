<?php
/**
 * ============================================
 * TEMPLATE ENGINE - SCHEDULE TEMPLATES
 * ============================================
 * Handles schedule templates:
 * - System templates
 * - User custom templates
 * - Apply templates to generate events
 * - Preview before applying
 * ============================================
 */

class TemplateEngine {
    private $db;
    private $userId;
    private $familyId;
    private static $instance = null;

    private function __construct($db, $userId = null, $familyId = null) {
        $this->db = $db;
        $this->userId = $userId;
        $this->familyId = $familyId;
    }

    public static function getInstance($db, $userId = null, $familyId = null) {
        if (self::$instance === null) {
            self::$instance = new self($db, $userId, $familyId);
        }
        return self::$instance;
    }

    public function setUser($userId, $familyId) {
        $this->userId = $userId;
        $this->familyId = $familyId;
        return $this;
    }

    // ============================================
    // GET TEMPLATES
    // ============================================

    public function getAll(): array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM schedule_templates
            WHERE is_system = 1
               OR is_public = 1
               OR user_id = ?
               OR family_id = ?
            ORDER BY is_system DESC, use_count DESC, name ASC
        ");
        $stmt->execute([$this->userId, $this->familyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSystemTemplates(): array {
        $stmt = $this->db->prepare("
            SELECT * FROM schedule_templates
            WHERE is_system = 1
            ORDER BY use_count DESC, name ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUserTemplates(): array {
        $stmt = $this->db->prepare("
            SELECT * FROM schedule_templates
            WHERE user_id = ? OR family_id = ?
            ORDER BY use_count DESC, name ASC
        ");
        $stmt->execute([$this->userId, $this->familyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $templateId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM schedule_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ============================================
    // CREATE TEMPLATE
    // ============================================

    public function create(array $data): int {
        if (empty($data['name']) || empty($data['pattern_json'])) {
            throw new InvalidArgumentException('Name and pattern are required');
        }

        // Validate pattern JSON
        $pattern = is_array($data['pattern_json'])
            ? $data['pattern_json']
            : json_decode($data['pattern_json'], true);

        if (!is_array($pattern)) {
            throw new InvalidArgumentException('Invalid pattern format');
        }

        $stmt = $this->db->prepare("
            INSERT INTO schedule_templates
            (user_id, family_id, name, description, icon, color, pattern_json, is_public, is_system)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
        ");

        $stmt->execute([
            $this->userId,
            $this->familyId,
            $data['name'],
            $data['description'] ?? null,
            $data['icon'] ?? '📋',
            $data['color'] ?? '#667eea',
            is_array($data['pattern_json']) ? json_encode($data['pattern_json']) : $data['pattern_json'],
            $data['is_public'] ?? 0
        ]);

        return (int)$this->db->lastInsertId();
    }

    // ============================================
    // UPDATE TEMPLATE
    // ============================================

    public function update(int $templateId, array $data): bool {
        $template = $this->getById($templateId);
        if (!$template) {
            throw new InvalidArgumentException('Template not found');
        }

        // Can only update own templates
        if ($template['user_id'] != $this->userId && !$template['is_system']) {
            throw new Exception('Cannot modify this template');
        }

        $updates = [];
        $params = [];

        $allowedFields = ['name', 'description', 'icon', 'color', 'pattern_json', 'is_public'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = ?";
                $value = $data[$field];
                if ($field === 'pattern_json' && is_array($value)) {
                    $value = json_encode($value);
                }
                $params[] = $value;
            }
        }

        if (empty($updates)) return false;

        $updates[] = "updated_at = NOW()";
        $params[] = $templateId;

        $stmt = $this->db->prepare("
            UPDATE schedule_templates
            SET " . implode(', ', $updates) . "
            WHERE id = ?
        ");

        return $stmt->execute($params);
    }

    // ============================================
    // DELETE TEMPLATE
    // ============================================

    public function delete(int $templateId): bool {
        $template = $this->getById($templateId);
        if (!$template) return false;

        // Cannot delete system templates
        if ($template['is_system']) {
            throw new Exception('Cannot delete system template');
        }

        // Can only delete own templates
        if ($template['user_id'] != $this->userId) {
            throw new Exception('Cannot delete this template');
        }

        $stmt = $this->db->prepare("DELETE FROM schedule_templates WHERE id = ?");
        return $stmt->execute([$templateId]);
    }

    // ============================================
    // PREVIEW TEMPLATE APPLICATION
    // ============================================

    public function preview(int $templateId, string $date): array {
        $template = $this->getById($templateId);
        if (!$template) {
            throw new InvalidArgumentException('Template not found');
        }

        $pattern = json_decode($template['pattern_json'], true);
        if (!is_array($pattern)) {
            throw new Exception('Invalid template pattern');
        }

        $events = [];
        $baseDate = new DateTime($date);

        foreach ($pattern as $item) {
            $startTime = $item['start_offset'] ?? '09:00';
            $endTime = $item['end_offset'] ?? '10:00';

            $startsAt = (clone $baseDate)->setTime(
                (int)substr($startTime, 0, 2),
                (int)substr($startTime, 3, 2)
            );
            $endsAt = (clone $baseDate)->setTime(
                (int)substr($endTime, 0, 2),
                (int)substr($endTime, 3, 2)
            );

            $events[] = [
                'title' => $item['title'] ?? 'Untitled',
                'starts_at' => $startsAt->format('Y-m-d H:i:s'),
                'ends_at' => $endsAt->format('Y-m-d H:i:s'),
                'kind' => $item['kind'] ?? 'todo',
                'color' => $item['color'] ?? $template['color'],
                'focus_mode' => $item['focus_mode'] ?? 0,
                'notes' => $item['notes'] ?? null,
                'is_preview' => true
            ];
        }

        return [
            'template' => $template,
            'date' => $date,
            'events' => $events
        ];
    }

    // ============================================
    // APPLY TEMPLATE - CREATE ACTUAL EVENTS
    // ============================================

    public function apply(int $templateId, string $date, array $options = []): array {
        $preview = $this->preview($templateId, $date);

        require_once __DIR__ . '/EventEngine.php';
        $eventEngine = EventEngine::getInstance($this->db, $this->userId, $this->familyId);

        $createdEvents = [];
        $errors = [];

        foreach ($preview['events'] as $eventData) {
            // Remove preview flag
            unset($eventData['is_preview']);

            // Add reminder if specified in options
            if (!empty($options['reminder_minutes'])) {
                $eventData['reminder_minutes'] = $options['reminder_minutes'];
            }

            // Add recurrence if specified
            if (!empty($options['recurrence_rule'])) {
                $eventData['recurrence_rule'] = $options['recurrence_rule'];
            }

            // Apply to specific user if specified
            if (!empty($options['assigned_to'])) {
                $eventData['assigned_to'] = $options['assigned_to'];
            }

            try {
                $result = $eventEngine->create($eventData);
                if ($result['success']) {
                    $createdEvents[] = $result['event'];
                } else {
                    $errors[] = [
                        'event' => $eventData['title'],
                        'error' => $result['error'] ?? 'Unknown error'
                    ];
                }
            } catch (Exception $e) {
                $errors[] = [
                    'event' => $eventData['title'],
                    'error' => $e->getMessage()
                ];
            }
        }

        // Increment use count
        $stmt = $this->db->prepare("
            UPDATE schedule_templates
            SET use_count = use_count + 1, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$templateId]);

        return [
            'success' => empty($errors),
            'template' => $preview['template'],
            'date' => $date,
            'created' => count($createdEvents),
            'events' => $createdEvents,
            'errors' => $errors
        ];
    }

    // ============================================
    // APPLY TEMPLATE TO DATE RANGE
    // ============================================

    public function applyToRange(int $templateId, string $startDate, string $endDate, array $options = []): array {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $results = [];

        while ($start <= $end) {
            $dayOfWeek = (int)$start->format('N');

            // Skip weekends if option set
            if (!empty($options['weekdays_only']) && $dayOfWeek > 5) {
                $start->modify('+1 day');
                continue;
            }

            // Skip specific days if exclusions set
            if (!empty($options['exclude_dates'])) {
                $dateStr = $start->format('Y-m-d');
                if (in_array($dateStr, $options['exclude_dates'])) {
                    $start->modify('+1 day');
                    continue;
                }
            }

            $result = $this->apply($templateId, $start->format('Y-m-d'), $options);
            $results[] = [
                'date' => $start->format('Y-m-d'),
                'success' => $result['success'],
                'created' => $result['created'],
                'errors' => $result['errors']
            ];

            $start->modify('+1 day');
        }

        $totalCreated = array_sum(array_column($results, 'created'));
        $totalErrors = array_sum(array_map(fn($r) => count($r['errors']), $results));

        return [
            'success' => $totalErrors === 0,
            'days_processed' => count($results),
            'total_created' => $totalCreated,
            'total_errors' => $totalErrors,
            'daily_results' => $results
        ];
    }

    // ============================================
    // CREATE TEMPLATE FROM EXISTING EVENTS
    // ============================================

    public function createFromEvents(string $name, string $date): int {
        require_once __DIR__ . '/EventEngine.php';
        $eventEngine = EventEngine::getInstance($this->db, $this->userId, $this->familyId);

        $events = $eventEngine->getByDate($date);

        if (empty($events)) {
            throw new Exception('No events found on this date');
        }

        $pattern = [];
        foreach ($events as $event) {
            $pattern[] = [
                'title' => $event['title'],
                'start_offset' => date('H:i', strtotime($event['starts_at'])),
                'end_offset' => date('H:i', strtotime($event['ends_at'])),
                'kind' => $event['kind'],
                'color' => $event['color'],
                'focus_mode' => $event['focus_mode'] ?? 0,
                'notes' => $event['notes']
            ];
        }

        return $this->create([
            'name' => $name,
            'description' => "Created from $date schedule",
            'pattern_json' => $pattern
        ]);
    }

    // ============================================
    // DUPLICATE TEMPLATE
    // ============================================

    public function duplicate(int $templateId, string $newName = null): int {
        $template = $this->getById($templateId);
        if (!$template) {
            throw new InvalidArgumentException('Template not found');
        }

        return $this->create([
            'name' => $newName ?? $template['name'] . ' (Copy)',
            'description' => $template['description'],
            'icon' => $template['icon'],
            'color' => $template['color'],
            'pattern_json' => $template['pattern_json'],
            'is_public' => 0 // Copies are private by default
        ]);
    }

    // ============================================
    // VALIDATE PATTERN
    // ============================================

    public static function validatePattern(array $pattern): array {
        $errors = [];

        foreach ($pattern as $index => $item) {
            if (empty($item['title'])) {
                $errors[] = "Item $index: Title is required";
            }

            if (empty($item['start_offset']) || !preg_match('/^\d{2}:\d{2}$/', $item['start_offset'])) {
                $errors[] = "Item $index: Invalid start time format (use HH:MM)";
            }

            if (empty($item['end_offset']) || !preg_match('/^\d{2}:\d{2}$/', $item['end_offset'])) {
                $errors[] = "Item $index: Invalid end time format (use HH:MM)";
            }

            if (!empty($item['start_offset']) && !empty($item['end_offset'])) {
                if ($item['start_offset'] >= $item['end_offset']) {
                    $errors[] = "Item $index: End time must be after start time";
                }
            }
        }

        return $errors;
    }
}
