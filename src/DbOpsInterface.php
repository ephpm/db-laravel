<?php

declare(strict_types=1);

namespace Ephpm\Db\Laravel;

/**
 * Backend abstraction for ePHPm's in-process DB bridge.
 *
 * The production implementation ({@see SapiDbOps}) calls the global
 * `ephpm_db_query()` / `ephpm_db_execute()` functions registered by the
 * ePHPm SAPI. Tests (and apps that want to run their suites on plain
 * php-cli) use {@see SqlitePdoDbOps}, which emulates the bridge over
 * `pdo_sqlite`.
 *
 * Method semantics intentionally mirror the native surface — MySQL-dialect
 * SQL with `?` placeholders, scalar-only parameters, `\Exception` errors
 * carrying the MySQL errno as the exception code and a
 * `SQLSTATE[xxxxx]: <backend message>` message — the connection adapts
 * those to Laravel's contracts, not the other way around.
 */
interface DbOpsInterface
{
    /**
     * Execute SQL and return the rows.
     *
     * @param list<null|bool|int|float|string> $params positional `?` bindings
     *
     * @return list<array<string, null|int|float|string>> associative rows
     *                                                    keyed by column name; integer/float columns come back as
     *                                                    native PHP int/float, NULL as null, text/blob as string.
     *                                                    A statement with no result set returns an empty array.
     *
     * @throws \Exception code = MySQL errno (e.g. 1062), message
     *                    `SQLSTATE[xxxxx]: <backend message>`
     */
    public function query(string $sql, array $params = []): array;

    /**
     * Execute SQL and return the OK metadata.
     *
     * Transaction statements (`BEGIN` / `COMMIT` / `ROLLBACK` /
     * `SAVEPOINT x` / `ROLLBACK TO SAVEPOINT x`) flow through as plain
     * SQL — the per-thread session tracks the transaction state. A SELECT
     * routed through execute returns zeros rather than throwing.
     *
     * @param list<null|bool|int|float|string> $params positional `?` bindings
     *
     * @return array{affected_rows: int, last_insert_id: int}
     *
     * @throws \Exception code = MySQL errno (e.g. 1062), message
     *                    `SQLSTATE[xxxxx]: <backend message>`
     */
    public function execute(string $sql, array $params = []): array;

    /**
     * Execute SQL once and report what it actually did — the unified entry
     * point mirroring the native `ephpm_db_run()` (ePHPm issue #263).
     *
     * `has_rowset` is read from the executed statement, not guessed from the
     * SQL's first keyword, so a statement run through {@see \Ephpm\Db\Laravel\EphpmConnection::statement()}
     * or `unprepared()` that turns out to produce a rowset is handled
     * correctly instead of being mis-routed through `execute()` (which would
     * discard the rows). `rows`/`columns` are empty for an OK outcome;
     * `affected_rows`/`last_insert_id` are zero for a result set.
     *
     * @param list<null|bool|int|float|string> $params positional `?` bindings
     *
     * @return array{has_rowset: bool, rows: list<array<string, null|int|float|string>>, columns: list<array{name: string, type: ?string}>, affected_rows: int, last_insert_id: int}
     *
     * @throws \Exception code = MySQL errno (e.g. 1062), message
     *                    `SQLSTATE[xxxxx]: <backend message>`
     */
    public function run(string $sql, array $params = []): array;
}
