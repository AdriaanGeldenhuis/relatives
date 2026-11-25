<?php
declare(strict_types=1);

final class ScheduledJobs
{
    public static function run(PDO $db, string $name, callable $job): int
    {
        $lockName = 'relatives:job:' . $name;
        $lock = $db->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            return 0;
        }
        try {
            self::stamp($db, $name, 'last_started_at');
            $job();
            self::stamp($db, $name, 'last_success_at');
            return 0;
        } catch (Throwable $error) {
            self::stamp($db, $name, 'last_failure_at');
            error_log($name . ' failed: ' . $error->getMessage());
            return 1;
        } finally {
            $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }

    private static function stamp(PDO $db, string $name, string $column): void
    {
        $db->prepare("INSERT INTO scheduled_job_status (job_name, $column) VALUES (?, NOW())
            ON DUPLICATE KEY UPDATE $column = NOW()")->execute([$name]);
    }

    public static function once(PDO $db, string $key, callable $create): bool
    {
        $db->beginTransaction();
        try {
            $insert = $db->prepare('INSERT IGNORE INTO scheduled_deliveries (event_key) VALUES (?)');
            $insert->execute([hash('sha256', $key)]);
            if ($insert->rowCount() !== 1 || $create() === false) {
                $db->rollBack();
                return false;
            }
            $db->commit();
            return true;
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    public static function zone(?string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name ?: 'Africa/Johannesburg');
        } catch (Exception) {
            return new DateTimeZone('Africa/Johannesburg');
        }
    }
}
