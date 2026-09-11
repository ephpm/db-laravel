<?php

declare(strict_types=1);

namespace Ephpm\Db\Laravel;

/**
 * Test/development polyfill of the ePHPm DB bridge over `pdo_sqlite`.
 *
 * ePHPm's bridge runs MySQL-dialect SQL through litewire's translator into
 * SQLite; this class emulates that shape closely enough for unit tests to
 * run on plain php-cli:
 *
 * - `?` placeholders, scalar-only parameters (null/bool/int/float/string),
 *   native int/float column values (PHP 8.1+ pdo_sqlite behavior).
 * - Errors are re-thrown as plain `\Exception` with the exception code and
 *   `SQLSTATE[xxxxx]: <raw SQLite message>` message the real bridge
 *   produces. The errno mapping mirrors litewire's `error_map.rs`:
 *   unique/primary-key violation → 1062 (23000), foreign key → 1452
 *   (23000), locked → 1205 (HY000), readonly → 1290 (HY000), everything
 *   else — including syntax errors and missing tables — → 1105 (HY000).
 * - `BEGIN` / `COMMIT` / `ROLLBACK` / `SAVEPOINT` flow through as plain
 *   SQL, exactly like the bridge's per-thread session.
 *
 * What it does NOT emulate: litewire's MySQL→SQLite translation. SQL you
 * run through this polyfill must be parseable by SQLite itself (SQLite
 * happily accepts backtick identifiers, so query-builder output works;
 * MySQL-specific DDL like `AUTO_INCREMENT` does not). It is for tests
 * only — never use it in production.
 */
final class SqlitePdoDbOps implements DbOpsInterface
{
    private readonly \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, false);
    }

    /**
     * The underlying PDO handle — handy for creating test tables with
     * SQLite-dialect DDL without going through the driver.
     */
    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->exec($sql, $params);

        if ($stmt->columnCount() === 0) {
            // OK result (no rowset) — empty array, matching the bridge.
            return [];
        }

        /** @var list<array<string, null|int|float|string>> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function execute(string $sql, array $params = []): array
    {
        $stmt = $this->exec($sql, $params);

        return [
            'affected_rows' => $stmt->rowCount(),
            'last_insert_id' => (int) $this->pdo->lastInsertId(),
        ];
    }

    public function run(string $sql, array $params = []): array
    {
        $stmt = $this->exec($sql, $params);
        $ncols = $stmt->columnCount();
        $hasRowset = $ncols > 0;

        // Column metadata is read from the statement, so it is present even
        // for a zero-row result set (issue #262).
        $columns = [];
        for ($i = 0; $i < $ncols; $i++) {
            /** @var array<string, mixed>|false $meta */
            $meta = $stmt->getColumnMeta($i);
            $decl = \is_array($meta) ? ($meta['sqlite:decl_type'] ?? null) : null;
            $columns[] = [
                'name' => \is_array($meta) ? (string) ($meta['name'] ?? '') : '',
                'type' => \is_string($decl) && $decl !== '' ? $decl : null,
            ];
        }

        if ($hasRowset) {
            /** @var list<array<string, null|int|float|string>> $rows */
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'has_rowset' => true,
                'rows' => $rows,
                'columns' => $columns,
                'affected_rows' => 0,
                'last_insert_id' => 0,
            ];
        }

        return [
            'has_rowset' => false,
            'rows' => [],
            'columns' => [],
            'affected_rows' => $stmt->rowCount(),
            'last_insert_id' => (int) $this->pdo->lastInsertId(),
        ];
    }

    /**
     * @param list<null|bool|int|float|string> $params
     */
    private function exec(string $sql, array $params): \PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);

            $position = 1;
            foreach (array_values($params) as $value) {
                if ($value === null) {
                    $stmt->bindValue($position, null, \PDO::PARAM_NULL);
                } elseif (\is_bool($value)) {
                    $stmt->bindValue($position, (int) $value, \PDO::PARAM_INT);
                } elseif (\is_int($value)) {
                    $stmt->bindValue($position, $value, \PDO::PARAM_INT);
                } elseif (\is_float($value) || \is_string($value)) {
                    $stmt->bindValue($position, $value);
                } else {
                    // Same message the C wrapper produces for a non-scalar.
                    throw new \Exception(sprintf(
                        'ephpm_db: unsupported parameter type %s (only null, '
                        . 'bool, int, float, and string parameters bind)',
                        get_debug_type($value)
                    ));
                }
                $position++;
            }

            $stmt->execute();

            return $stmt;
        } catch (\PDOException $e) {
            $this->throwMapped($e);
        }
    }

    /**
     * Re-throw a PDOException in the exact shape the native bridge uses:
     * plain `\Exception`, code = mapped MySQL errno, message
     * `SQLSTATE[xxxxx]: <raw backend message>`.
     *
     * The mapping mirrors litewire's `error_map.rs` classify() so tests
     * exercise the same codes the real runtime produces.
     */
    private function throwMapped(\PDOException $e): never
    {
        $message = \is_string($e->errorInfo[2] ?? null) ? $e->errorInfo[2] : $e->getMessage();
        $lower = strtolower($message);

        if (str_contains($lower, 'unique constraint failed')
            || str_contains($lower, 'primary key constraint failed')) {
            [$code, $sqlstate] = [1062, '23000'];
        } elseif (str_contains($lower, 'foreign key constraint failed')) {
            [$code, $sqlstate] = [1452, '23000'];
        } elseif (str_contains($lower, 'database is locked')
            || str_contains($lower, 'database table is locked')) {
            [$code, $sqlstate] = [1205, 'HY000'];
        } elseif (str_contains($lower, 'readonly database')) {
            [$code, $sqlstate] = [1290, 'HY000'];
        } else {
            // litewire's conservative fallback: ER_UNKNOWN_ERROR.
            [$code, $sqlstate] = [1105, 'HY000'];
        }

        throw new \Exception(sprintf('SQLSTATE[%s]: %s', $sqlstate, $message), $code, $e);
    }
}
