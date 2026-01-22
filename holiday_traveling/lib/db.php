<?php
/**
 * Holiday Traveling - Database Helper
 * Wraps core DB class with module-specific helpers
 */
declare(strict_types=1);

class HT_DB {
    private static ?PDO $db = null;

    /**
     * Get database connection (uses core DB singleton)
     */
    public static function get(): PDO {
        if (self::$db === null) {
            self::$db = DB::getInstance();
        }
        return self::$db;
    }

    /**
     * Execute a query and return all results
     */
    public static function fetchAll(string $sql, array $params = []): array {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Execute a query and return single row
     */
    public static function fetchOne(string $sql, array $params = []): ?array {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }

    /**
     * Execute a query and return single column value
     */
    public static function fetchColumn(string $sql, array $params = []): mixed {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * Execute an insert and return last insert ID
     */
    public static function insert(string $table, array $data): int {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $stmt = self::get()->prepare($sql);
        $stmt->execute(array_values($data));

        return (int) self::get()->lastInsertId();
    }

    /**
     * Execute an update and return affected rows
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $setParts = [];
        foreach (array_keys($data) as $column) {
            $setParts[] = "{$column} = ?";
        }
        $setClause = implode(', ', $setParts);

        $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";
        $stmt = self::get()->prepare($sql);
        $stmt->execute(array_merge(array_values($data), $whereParams));

        return $stmt->rowCount();
    }

    /**
     * Execute a delete and return affected rows
     */
    public static function delete(string $table, string $where, array $params = []): int {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Execute raw query (for complex queries)
     */
    public static function execute(string $sql, array $params = []): PDOStatement {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Begin transaction
     */
    public static function beginTransaction(): bool {
        return self::get()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public static function commit(): bool {
        return self::get()->commit();
    }

    /**
     * Rollback transaction
     */
    public static function rollback(): bool {
        return self::get()->rollBack();
    }
}
