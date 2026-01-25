<?php
declare(strict_types=1);

class Validator {
    public static function email(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function required(mixed $value): bool {
        if (is_string($value)) {
            return trim($value) !== '';
        }
        return !empty($value);
    }

    public static function minLength(string $value, int $min): bool {
        return mb_strlen($value) >= $min;
    }

    public static function maxLength(string $value, int $max): bool {
        return mb_strlen($value) <= $max;
    }

    public static function numeric(mixed $value): bool {
        return is_numeric($value);
    }

    public static function date(string $date): bool {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}