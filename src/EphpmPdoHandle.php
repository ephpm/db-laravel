<?php

declare(strict_types=1);

namespace Ephpm\Db\Laravel;

/**
 * PDO-shaped handle over the native bridge.
 *
 * `Illuminate\Database\Connection` and its `ManagesTransactions` trait
 * drive transactions through `$this->getPdo()->beginTransaction()` /
 * `commit()` / `rollBack()` / `exec()` / `inTransaction()` — including an
 * inline `getPdo()->commit()` inside `transaction()` itself. Rather than
 * copying trait bodies (which drift between Laravel 10/11/12), this class
 * stands in for PDO and routes those calls to `ephpm_db_execute()` as
 * plain SQL, which is exactly how the bridge's per-thread session expects
 * transaction control to arrive. That keeps every version of the trait —
 * events, DatabaseTransactionsManager bookkeeping, savepoint compilation —
 * working untouched.
 *
 * It is NOT a real \PDO (the property is untyped in illuminate/database,
 * documented `\PDO|\Closure`). Only the methods Laravel actually calls on
 * a non-prepared path are implemented; anything else is a hard error by
 * design.
 */
final class EphpmPdoHandle
{
    private int $lastInsertId = 0;

    private bool $inTransaction = false;

    public function __construct(private readonly DbOpsInterface $ops)
    {
    }

    public function beginTransaction(): bool
    {
        $this->ops->execute('BEGIN');
        $this->inTransaction = true;

        return true;
    }

    public function commit(): bool
    {
        $this->ops->execute('COMMIT');
        $this->inTransaction = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->ops->execute('ROLLBACK');
        $this->inTransaction = false;

        return true;
    }

    /**
     * Whether a bridge-level BEGIN is currently open. Checked by
     * Laravel 12's performRollBack() before issuing the ROLLBACK.
     */
    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    /**
     * Used by the transactions trait for SAVEPOINT / ROLLBACK TO SAVEPOINT
     * statements compiled by the query grammar.
     */
    public function exec(string $statement): int
    {
        return $this->ops->execute($statement)['affected_rows'];
    }

    /**
     * The auto-increment id of the most recent INSERT on this connection,
     * cached by {@see EphpmConnection} from ephpm_db_execute()'s return.
     * MySqlProcessor::processInsertGetId() calls this right after insert().
     */
    public function lastInsertId(?string $name = null): string
    {
        return (string) $this->lastInsertId;
    }

    public function setLastInsertId(int $id): void
    {
        $this->lastInsertId = $id;
    }

    /**
     * Laravel asks PDO for the server version (e.g. for query-grammar
     * feature gates like the MySQL 8.0.11 group-limit check). litewire's
     * MySQL frontend reports "8.0.36-litewire"; we mirror it.
     */
    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === \PDO::ATTR_SERVER_VERSION) {
            return '8.0.36-litewire';
        }

        return null;
    }

    /**
     * Backs Connection::escape() for strings. Quotes with doubled single
     * quotes and doubled backslashes — valid in the MySQL dialect litewire
     * parses, and free of charset assumptions.
     */
    public function quote(string $string, int $type = \PDO::PARAM_STR): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $string) . "'";
    }
}
