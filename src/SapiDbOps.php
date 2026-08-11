<?php

declare(strict_types=1);

namespace Ephpm\Db\Laravel;

/**
 * Backend that calls the global `ephpm_db_query()` / `ephpm_db_execute()`
 * functions registered by the ePHPm SAPI.
 *
 * Refuses to construct if those functions aren't present so we fail fast
 * outside the runtime instead of producing "Call to undefined function"
 * errors at request time. The functions exist only inside an ePHPm build
 * from current `main` (they are not in any tagged release yet) with an
 * active `[db.sqlite]` backend.
 */
final class SapiDbOps implements DbOpsInterface
{
    public function __construct()
    {
        if (!\function_exists('ephpm_db_query')) {
            throw new \RuntimeException(
                'ephpm DB bridge functions are not available. This driver only '
                . 'works inside the ePHPm runtime (unreleased main, with '
                . '[db.sqlite] configured); use Ephpm\\Db\\Laravel\\SqlitePdoDbOps '
                . 'in tests.'
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
}
