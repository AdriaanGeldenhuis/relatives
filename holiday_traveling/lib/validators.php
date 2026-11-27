<?php
/**
 * Holiday Traveling - Input Validators
 * Extended validation for trip-specific data
 */
declare(strict_types=1);

class HT_Validators {
    /**
     * Validate required field
     */
    public static function required(mixed $value, string $fieldName): ?string {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return "{$fieldName} is required";
        }
        return null;
    }

    /**
     * Validate string length
     */
    public static function length(string $value, int $min, int $max, string $fieldName): ?string {
        $len = mb_strlen($value);
        if ($len < $min) {
            return "{$fieldName} must be at least {$min} characters";
        }
        if ($len > $max) {
            return "{$fieldName} must not exceed {$max} characters";
        }
        return null;
    }

    /**
     * Validate date format (YYYY-MM-DD)
     */
    public static function date(string $date, string $fieldName): ?string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return "{$fieldName} must be in YYYY-MM-DD format";
        }

        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            return "{$fieldName} is not a valid date";
        }

        return null;
    }

    /**
     * Validate date is in future
     */
    public static function futureDate(string $date, string $fieldName): ?string {
        $error = self::date($date, $fieldName);
        if ($error) {
            return $error;
        }

        $d = new DateTime($date);
        $today = new DateTime('today');

        if ($d < $today) {
            return "{$fieldName} must be today or in the future";
        }

        return null;
    }

    /**
     * Validate date range (end >= start)
     */
    public static function dateRange(string $startDate, string $endDate): ?string {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);

        if ($end < $start) {
            return "End date must be on or after start date";
        }

        return null;
    }

    /**
     * Validate positive number
     */
    public static function positiveNumber(mixed $value, string $fieldName): ?string {
        if (!is_numeric($value)) {
            return "{$fieldName} must be a number";
        }

        if ((float) $value <= 0) {
            return "{$fieldName} must be a positive number";
        }

        return null;
    }

    /**
     * Validate non-negative number (including zero)
     */
    public static function nonNegativeNumber(mixed $value, string $fieldName): ?string {
        if (!is_numeric($value)) {
            return "{$fieldName} must be a number";
        }

        if ((float) $value < 0) {
            return "{$fieldName} cannot be negative";
        }

        return null;
    }

    /**
     * Validate integer in range
     */
    public static function intRange(mixed $value, int $min, int $max, string $fieldName): ?string {
        if (!is_numeric($value) || (int) $value != $value) {
            return "{$fieldName} must be a whole number";
        }

        $intVal = (int) $value;
        if ($intVal < $min || $intVal > $max) {
            return "{$fieldName} must be between {$min} and {$max}";
        }

        return null;
    }

    /**
     * Validate email
     */
    public static function email(string $email, string $fieldName = 'Email'): ?string {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return "{$fieldName} is not a valid email address";
        }
        return null;
    }

    /**
     * Validate currency code (3-letter ISO)
     */
    public static function currency(string $code): ?string {
        $validCurrencies = ['ZAR', 'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'NZD', 'JPY', 'CHF', 'CNY', 'INR', 'BWP', 'NAD', 'MZN'];

        if (!in_array(strtoupper($code), $validCurrencies)) {
            return "Invalid currency code";
        }

        return null;
    }

    /**
     * Validate enum value
     */
    public static function enum(string $value, array $allowed, string $fieldName): ?string {
        if (!in_array($value, $allowed)) {
            $allowedStr = implode(', ', $allowed);
            return "{$fieldName} must be one of: {$allowedStr}";
        }
        return null;
    }

    /**
     * Validate JSON string
     */
    public static function json(string $value, string $fieldName): ?string {
        json_decode($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return "{$fieldName} is not valid JSON";
        }
        return null;
    }

    /**
     * Validate trip data (all-in-one)
     */
    public static function tripData(array $data, bool $allowUntitled = false): array {
        $errors = [];

        // Required fields
        $title = $data['title'] ?? '';
        if ($title !== '' || !$allowUntitled) {
            $error = self::required($title, 'Title') ?: self::length($title, 3, 255, 'Title');
            if ($error) $errors['title'] = $error;
        }

        if ($err = self::required($data['destination'] ?? null, 'Destination')) {
            $errors['destination'] = $err;
        } elseif ($err = self::length($data['destination'], 2, 255, 'Destination')) {
            $errors['destination'] = $err;
        }

        if ($err = self::required($data['start_date'] ?? null, 'Start date')) {
            $errors['start_date'] = $err;
        } elseif ($err = self::date($data['start_date'], 'Start date')) {
            $errors['start_date'] = $err;
        }

        if ($err = self::required($data['end_date'] ?? null, 'End date')) {
            $errors['end_date'] = $err;
        } elseif ($err = self::date($data['end_date'], 'End date')) {
            $errors['end_date'] = $err;
        }

        // Date range check
        if (!isset($errors['start_date']) && !isset($errors['end_date'])) {
            if ($err = self::dateRange($data['start_date'], $data['end_date'])) {
                $errors['end_date'] = $err;
            }
        }

        // Optional fields with validation
        if (isset($data['travelers_count']) && $data['travelers_count'] !== '') {
            if ($err = self::intRange($data['travelers_count'], 1, 50, 'Number of travelers')) {
                $errors['travelers_count'] = $err;
            }
        }

        if (isset($data['budget_currency']) && $data['budget_currency'] !== '') {
            if ($err = self::currency($data['budget_currency'])) {
                $errors['budget_currency'] = $err;
            }
        }

        foreach (['budget_min' => 'Minimum budget', 'budget_comfort' => 'Comfortable budget', 'budget_max' => 'Maximum budget'] as $field => $label) {
            if (isset($data[$field]) && $data[$field] !== '') {
                if ($err = self::nonNegativeNumber($data[$field], $label)) $errors[$field] = $err;
            }
        }

        // Budget range check
        if (!isset($errors['budget_min']) && !isset($errors['budget_max'])) {
            $min = (float) ($data['budget_min'] ?? 0);
            $max = (float) ($data['budget_max'] ?? 0);
            if ($min > 0 && $max > 0 && $min > $max) {
                $errors['budget_max'] = 'Maximum budget must be greater than minimum';
            }
        }

        return $errors;
    }

    /**
     * Sanitize string for output
     */
    public static function sanitize(string $value): string {
        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Clean and prepare trip input data
     */
    public static function cleanTripInput(array $input): array {
        return [
            'title' => self::sanitize($input['title'] ?? ''),
            'destination' => self::sanitize($input['destination'] ?? ''),
            'origin' => self::sanitize($input['origin'] ?? ''),
            'start_date' => $input['start_date'] ?? '',
            'end_date' => $input['end_date'] ?? '',
            'travelers_count' => (int) ($input['travelers_count'] ?? 1),
            'travelers_json' => $input['travelers_json'] ?? null,
            'budget_currency' => strtoupper($input['budget_currency'] ?? 'ZAR'),
            'budget_min' => ($input['budget_min'] ?? '') === '' ? null : $input['budget_min'],
            'budget_comfort' => ($input['budget_comfort'] ?? '') === '' ? null : $input['budget_comfort'],
            'budget_max' => ($input['budget_max'] ?? '') === '' ? null : $input['budget_max'],
            'preferences_json' => $input['preferences_json'] ?? null,
        ];
    }
}
