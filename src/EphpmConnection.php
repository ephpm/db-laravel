<?php

declare(strict_types=1);

namespace Ephpm\Db\Laravel;

use Exception;
use Generator;
use Illuminate\Database\MySqlConnection;

/**
 * Laravel database connection over ePHPm's in-process DB bridge.
 *
 * Extends MySqlConnection so the query grammar, schema grammar, and post
 * processor are all MySQL — the SQL Laravel emits is exactly what
 * litewire's MySQL-dialect translator expects. Every code path that would
 * touch a real PDO prepared statement is overridden to call
 * `ephpm_db_query()` / `ephpm_db_execute()` instead; transaction control
 * reaches the bridge as plain SQL via {@see EphpmPdoHandle}.
 *
 * Notable semantics:
 * - `select()` returns arrays of stdClass (Laravel's default fetch shape).
 * - `cursor()` yields stdClass rows but is NOT streaming — the bridge
 *   buffers the full result set before the generator starts.
 * - `insertGetId()` works: the bridge returns `last_insert_id` from every
 *   execute, the connection caches the latest non-zero value, and
 *   MySqlProcessor reads it back — via `getLastInsertId()` on newer
 *   Laravel releases, via the handle's `lastInsertId()` on older ones.
 * - Nested transactions use SAVEPOINTs (`SAVEPOINT transN` /
 *   `ROLLBACK TO SAVEPOINT transN`), which litewire's translator passes
 *   through to SQLite.
 * - Native errors carry the MySQL errno as the exception code, so unique
 *   violations (1062) surface as UniqueConstraintViolationException.
 */
class EphpmConnection extends MySqlConnection
{
    /**
     * Redeclared for Laravel versions whose MySqlConnection does not have
     * it (the property backs getLastInsertId() on newer releases; on
     * older ones the processor asks the PDO handle instead — both read
     * the value cached from ephpm_db_execute()'s return).
     *
     * @var string|int|null
     */
    protected $lastInsertId;

    protected DbOpsInterface $ops;

    protected EphpmPdoHandle $handle;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        DbOpsInterface $ops,
        string $database = '',
        string $tablePrefix = '',
        array $config = [],
    ) {
        $this->ops = $ops;
        $this->handle = new EphpmPdoHandle($ops);

        parent::__construct($this->handle, $database, $tablePrefix, $config);
    }

    /**
     * The bridge backend this connection runs on.
     */
    public function getOps(): DbOpsInterface
    {
        return $this->ops;
    }

    /**
     * The auto-increment id returned by the most recent INSERT through
     * this connection (0 if none yet). This is what `insertGetId()` reads.
     */
    public function getRawLastInsertId(): int
    {
        return (int) $this->handle->lastInsertId();
    }

    /**
     * Run a select statement against the database.
     *
     * @param string $query
     * @param array  $bindings
     * @param bool   $useReadPdo ignored — the bridge has a single
     *                           per-thread session (no read/write split)
     *
     * @return array<int, \stdClass>
     */
    public function select($query, $bindings = [], $useReadPdo = true): array
    {
        $params = $this->bridgeBindings($bindings);

        return $this->run($query, $bindings, function ($query) use ($params) {
            if ($this->pretending()) {
                return [];
            }

            $rows = $this->ops->query($query, $params);

            return array_map(static fn (array $row): \stdClass => (object) $row, $rows);
        });
    }

    /**
     * Run a select statement and return a generator.
     *
     * NOT streaming: ephpm_db_query() buffers the complete result set
     * before the first row is yielded. The generator shape exists for API
     * compatibility (`->cursor()`, `Model::cursor()`), not for constant
     * memory use.
     *
     * @param string $query
     * @param array  $bindings
     * @param bool   $useReadPdo ignored
     */
    public function cursor($query, $bindings = [], $useReadPdo = true): Generator
    {
        $params = $this->bridgeBindings($bindings);

        $rows = $this->run($query, $bindings, function ($query) use ($params) {
            if ($this->pretending()) {
                return [];
            }

            return $this->ops->query($query, $params);
        });

        foreach ($rows as $row) {
            yield (object) $row;
        }
    }

    /**
     * Multi-rowset selects are a PDO/mysqlnd feature the bridge does not
     * have — ephpm_db_query() stages exactly one result set.
     *
     * @param string $query
     * @param array  $bindings
     * @param bool   $useReadPdo
     */
    public function selectResultSets($query, $bindings = [], $useReadPdo = true): never
    {
        throw new \RuntimeException(
            'ephpm-db: selectResultSets() is not supported — the ePHPm DB '
            . 'bridge returns a single result set per statement.'
        );
    }

    /**
     * Run an insert statement against the database.
     *
     * Overridden because newer MySqlConnection versions implement this
     * with a PDO prepared statement; here it routes through
     * ephpm_db_execute() and caches the returned last_insert_id — which
     * is how `insertGetId()` gets its value (Laravel inserts first, then
     * asks the connection for the id).
     *
     * @param string      $query
     * @param array       $bindings
     * @param string|null $sequence ignored — MySQL semantics, no sequences
     */
    public function insert($query, $bindings = [], $sequence = null): bool
    {
        return $this->statement($query, $bindings);
    }

    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param string $query
     * @param array  $bindings
     */
    public function statement($query, $bindings = []): bool
    {
        $params = $this->bridgeBindings($bindings);

        return $this->run($query, $bindings, function ($query) use ($params) {
            if ($this->pretending()) {
                return true;
            }

            $result = $this->ops->execute($query, $params);

            $this->recordsHaveBeenModified();
            $this->rememberLastInsertId($result['last_insert_id']);

            return true;
        });
    }

    /**
     * Run an SQL statement and get the number of rows affected.
     *
     * @param string $query
     * @param array  $bindings
     */
    public function affectingStatement($query, $bindings = []): int
    {
        $params = $this->bridgeBindings($bindings);

        return $this->run($query, $bindings, function ($query) use ($params) {
            if ($this->pretending()) {
                return 0;
            }

            $result = $this->ops->execute($query, $params);

            $this->recordsHaveBeenModified($result['affected_rows'] > 0);
            $this->rememberLastInsertId($result['last_insert_id']);

            return $result['affected_rows'];
        });
    }

    /**
     * Run a raw, unprepared query against the bridge.
     *
     * @param string $query
     */
    public function unprepared($query): bool
    {
        return $this->run($query, [], function ($query) {
            if ($this->pretending()) {
                return true;
            }

            $result = $this->ops->execute($query);

            $this->recordsHaveBeenModified($result['affected_rows'] > 0);
            $this->rememberLastInsertId($result['last_insert_id']);

            return true;
        });
    }

    /**
     * Prepare bindings for the bridge: run Laravel's normal
     * prepareBindings() (DateTimeInterface → grammar-formatted string,
     * bool → int), then reject anything the bridge cannot bind — only
     * null/bool/int/float/string cross the FFI boundary — with a clear
     * error instead of letting the C wrapper throw mid-request. Returns a
     * positional list, which is what ephpm_db_query()/execute() expect.
     *
     * This deliberately does NOT live in prepareBindings() itself:
     * Laravel calls prepareBindings() again while *constructing* a
     * QueryException, and throwing there would replace the QueryException
     * with a bare InvalidArgumentException.
     *
     * @param array $bindings
     *
     * @return list<null|bool|int|float|string>
     */
    protected function bridgeBindings(array $bindings): array
    {
        $bindings = $this->prepareBindings($bindings);

        foreach ($bindings as $key => $value) {
            if ($value !== null && !\is_scalar($value)) {
                throw new \InvalidArgumentException(sprintf(
                    'ephpm-db: unsupported binding of type %s at [%s] — the '
                    . 'ePHPm DB bridge only binds null, bool, int, float, and '
                    . 'string parameters.',
                    get_debug_type($value),
                    $key
                ));
            }
        }

        return array_values($bindings);
    }

    /**
     * Detect a unique-key violation.
     *
     * The bridge reports MySQL errno 1062 as the exception code, but its
     * message is `SQLSTATE[23000]: <raw backend message>` (e.g. "UNIQUE
     * constraint failed: users.email"), not PDO's "Integrity constraint
     * violation: 1062 ..." phrasing — so the parent's regex alone would
     * miss it. Match the errno first, keep the parent regex as a fallback.
     *
     * @return bool
     */
    protected function isUniqueConstraintError(Exception $exception)
    {
        return (int) $exception->getCode() === 1062
            || parent::isUniqueConstraintError($exception);
    }

    /**
     * No-op: there is no connection to lose. The bridge session is
     * per-thread inside the ePHPm process and reconnects itself.
     */
    public function reconnectIfMissingConnection(): void
    {
    }

    /**
     * No-op: see reconnectIfMissingConnection().
     */
    public function reconnect()
    {
    }

    /**
     * No-op: the per-thread bridge session is owned by the ePHPm runtime,
     * not by this object — and discarding the handle would break the
     * transaction bookkeeping in the ManagesTransactions trait.
     */
    public function disconnect(): void
    {
    }

    private function rememberLastInsertId(int $id): void
    {
        // MySQL's LAST_INSERT_ID() keeps its value across statements that
        // don't insert; the bridge reports 0 for those, so only a non-zero
        // value replaces the cache.
        if ($id > 0) {
            $this->handle->setLastInsertId($id);
            $this->lastInsertId = $id;
        }
    }
}
