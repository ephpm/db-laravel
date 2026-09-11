<?php

declare(strict_types=1);

namespace Ephpm\Db\Laravel;

/**
 * Backend that calls the global `ephpm_db_query()` / `ephpm_db_execute()`
 * functions registered by the ePHPm SAPI.
 *
 * Refuses to construct if those functions aren't present so we fail fast
 * outside the runtime instead of producing "Call to undefined function"
 * errors at request time. The functions ship in every ePHPm release from
 * v0.6.3 onward, and are only registered when `[db.sqlite]` is active.
 */
final class SapiDbOps implements DbOpsInterface
{
    public function __construct()
    {
        if (!\function_exists('ephpm_db_query')) {
            throw new \RuntimeException(
                'ephpm DB bridge functions are not available. This driver only '
                . 'works inside the ePHPm runtime (v0.6.3+, with [db.sqlite] '
                . 'configured); use Ephpm\\Db\\Laravel\\SqlitePdoDbOps in tests.'
            );
        }
    }

    public function query(string $sql, array $params = []): array
    {
        /** @var list<array<string, null|int|float|string>> */
        return \ephpm_db_query($sql, $params);
    }

    public function execute(string $sql, array $params = []): array
    {
        /** @var array{affected_rows: int, last_insert_id: int} */
        return \ephpm_db_execute($sql, $params);
    }

    public function run(string $sql, array $params = []): array
    {
        if (\function_exists('ephpm_db_run')) {
            /** @var array{has_rowset: bool, rows: list<array<string, null|int|float|string>>, columns: list<array{name: string, type: ?string}>, affected_rows: int, last_insert_id: int} */
            return \ephpm_db_run($sql, $params);
        }

        // Floor-preserving fallback for an ePHPm predating ephpm_db_run:
        // route the way the connection used to. A statement with a rowset run
        // through here still returns its rows; an OK statement returns zeros
        // for rows/columns. Column names, when present, come from
        // ephpm_db_columns() (also newer) else the first row.
        if (self::mayReturnRows($sql)) {
            $rows = $this->query($sql, $params);
            $columns = \function_exists('ephpm_db_columns')
                ? \ephpm_db_columns()
                : self::columnsFromRows($rows);

            return [
                'has_rowset' => true,
                'rows' => $rows,
                'columns' => $columns,
                'affected_rows' => 0,
                'last_insert_id' => 0,
            ];
        }

        $ok = $this->execute($sql, $params);

        return [
            'has_rowset' => false,
            'rows' => [],
            'columns' => [],
            'affected_rows' => (int) ($ok['affected_rows'] ?? 0),
            'last_insert_id' => (int) ($ok['last_insert_id'] ?? 0),
        ];
    }

    /** Keyword classifier used ONLY by the {@see run()} fallback. */
    private static function mayReturnRows(string $sql): bool
    {
        $q = ltrim($sql, " \t\r\n(");
        while (preg_match('/^(?:\/\*.*?\*\/|--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$))\s*/s', $q, $m)) {
            $q = substr($q, \strlen($m[0]));
        }

        return (bool) preg_match('/^(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH|VALUES|TABLE)\b/i', $q);
    }

    /**
     * @param list<array<string, null|int|float|string>> $rows
     *
     * @return list<array{name: string, type: ?string}>
     */
    private static function columnsFromRows(array $rows): array
    {
        if (!isset($rows[0])) {
            return [];
        }

        $columns = [];
        foreach (array_keys($rows[0]) as $name) {
            $columns[] = ['name' => (string) $name, 'type' => null];
        }

        return $columns;
    }
}
