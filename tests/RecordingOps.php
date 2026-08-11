<?php

declare(strict_types=1);

namespace Ephpm\Db\Laravel\Tests;

use Ephpm\Db\Laravel\DbOpsInterface;

/**
 * Decorator that records every SQL string passed to the backend, so tests
 * can assert exactly what reaches the bridge (e.g. the SAVEPOINT syntax
 * litewire's translator documents).
 */
final class RecordingOps implements DbOpsInterface
{
    /** @var list<string> */
    public array $executed = [];

    /** @var list<string> */
    public array $queried = [];

    public function __construct(private readonly DbOpsInterface $inner)
    {
    }

    public function query(string $sql, array $params = []): array
    {
        $this->queried[] = $sql;

        return $this->inner->query($sql, $params);
    }

    public function execute(string $sql, array $params = []): array
    {
        $this->executed[] = $sql;

        return $this->inner->execute($sql, $params);
    }
}
