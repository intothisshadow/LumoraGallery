<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Database
 *
 * Thin PDO wrapper with:
 *   - Table-prefix support via {PREFIX} in SQL strings
 *   - Static singleton connection
 *   - Convenience helpers: fetchAll, fetchOne, fetchValue, insert, update, delete
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class LumoraDB
{
    private static ?PDO    $pdo    = null;
    private static string  $prefix = '';

    /**
     * Current transaction nesting depth. 0 = no active transaction.
     * Used by beginTransaction()/commit()/rollBack() below to make nested
     * calls safe via SAVEPOINTs instead of throwing, since PDO itself (as of
     * PHP 8.0) throws a PDOException on a second beginTransaction() call
     * rather than silently nesting.
     */
    private static int $txDepth = 0;

    // ── Connection ────────────────────────────────────────────────────────────

    public static function connect(
        string $host,
        string $dbname,
        string $user,
        string $pass,
        string $prefix  = 'lum_',
        string $charset = 'utf8mb4'
    ): void {
        if (self::$pdo !== null) return;

        self::$prefix = $prefix;

        $dsn     = "mysql:host={$host};dbname={$dbname};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            self::$pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new RuntimeException('PDO: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('Database not connected.');
        }
        return self::$pdo;
    }

    // ── Prefix helpers ────────────────────────────────────────────────────────

    /** Return the current table prefix. */
    public static function prefix(): string { return self::$prefix; }

    /** Return a prefixed table name. */
    public static function table(string $name): string { return self::$prefix . $name; }

    // ── Core query ────────────────────────────────────────────────────────────

    /**
     * Prepare and execute a query.
     * {PREFIX} in $sql is replaced with the configured prefix before execution.
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $sql  = str_replace('{PREFIX}', self::$prefix, $sql);
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // ── Read helpers ──────────────────────────────────────────────────────────

    /** Fetch all rows as an associative array. */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** Fetch a single row, or null if no rows. */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row !== false ? $row : null;
    }

    /** Fetch a single column value, or null if no rows. */
    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $val = self::query($sql, $params)->fetchColumn();
        return $val !== false ? $val : null;
    }

    // ── Write helpers ─────────────────────────────────────────────────────────

    /**
     * Insert a row into a prefixed table.
     * $table should be the un-prefixed name, e.g. 'categories'.
     * Returns the last insert ID.
     */
    public static function insert(string $table, array $data): string
    {
        $t     = '`' . self::table($table) . '`';
        $cols  = implode(', ', array_map(static fn($k) => "`{$k}`", array_keys($data)));
        $phs   = implode(', ', array_fill(0, count($data), '?'));
        self::query("INSERT INTO {$t} ({$cols}) VALUES ({$phs})", array_values($data));
        return self::pdo()->lastInsertId();
    }

    /**
     * Update rows in a prefixed table.
     * Returns the number of affected rows.
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $t   = '`' . self::table($table) . '`';
        $set = implode(', ', array_map(static fn($k) => "`{$k}` = ?", array_keys($data)));
        return self::query(
            "UPDATE {$t} SET {$set} WHERE {$where}",
            [...array_values($data), ...$whereParams]
        )->rowCount();
    }

    /**
     * Delete rows from a prefixed table.
     * Returns the number of affected rows.
     */
    public static function delete(string $table, string $where, array $params = []): int
    {
        $t = '`' . self::table($table) . '`';
        return self::query("DELETE FROM {$t} WHERE {$where}", $params)->rowCount();
    }

    /** Return the last auto-increment ID. */
    public static function lastInsertId(): string
    {
        return self::pdo()->lastInsertId();
    }

    // ── Transactions ──────────────────────────────────────────────────────────
    //
    // Nesting-safe via SAVEPOINTs (InnoDB supports these). A service method
    // that opens its own transaction (e.g. GroupService::createGroup()) can
    // therefore be called safely from within an already-open transaction —
    // by another service method, or by a test harness wrapping each test in
    // an outer transaction for rollback-based isolation — without PDO's
    // "There is already an active transaction" exception. Only the outermost
    // beginTransaction()/commit()/rollBack() pair touches the real PDO
    // transaction; inner calls use named SAVEPOINTs instead.

    public static function beginTransaction(): void
    {
        if (self::$txDepth === 0) {
            self::pdo()->beginTransaction();
        } else {
            self::pdo()->exec('SAVEPOINT lumora_sp_' . self::$txDepth);
        }
        self::$txDepth++;
    }

    public static function commit(): void
    {
        self::$txDepth = max(0, self::$txDepth - 1);
        if (self::$txDepth === 0) {
            // A DDL statement (CREATE/ALTER/DROP TABLE) issued anywhere inside
            // this transaction causes MySQL/MariaDB to implicitly commit —
            // silently, from PDO's perspective, since the server does this
            // without PDO being asked to. inTransaction() reflects the real
            // server-side state though, so guard here rather than letting
            // commit() throw "There is no active transaction" when that's
            // happened (e.g. SchemaService/migration-replaying tests).
            if (self::pdo()->inTransaction()) {
                self::pdo()->commit();
            }
        } else {
            self::pdo()->exec('RELEASE SAVEPOINT lumora_sp_' . self::$txDepth);
        }
    }

    public static function rollBack(): void
    {
        self::$txDepth = max(0, self::$txDepth - 1);
        if (self::$txDepth === 0) {
            // See the comment in commit() above — same implicit-commit-from-DDL
            // scenario applies here.
            if (self::pdo()->inTransaction()) {
                self::pdo()->rollBack();
            }
        } else {
            self::pdo()->exec('ROLLBACK TO SAVEPOINT lumora_sp_' . self::$txDepth);
        }
    }
}
