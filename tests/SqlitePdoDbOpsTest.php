<?php

declare(strict_types=1);

namespace Ephpm\Db\Laravel\Tests;

use Ephpm\Db\Laravel\SqlitePdoDbOps;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the polyfill reproduces the native ephpm_db_* result and error
 * shapes documented in ephpm_wrapper.c / db_bridge.rs.
 */
final class SqlitePdoDbOpsTest extends TestCase
{
    private function ops(): SqlitePdoDbOps
    {
        $ops = new SqlitePdoDbOps();
        $ops->pdo()->exec(
            'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT, n INTEGER, f REAL)'
        );
        $ops->pdo()->exec('CREATE UNIQUE INDEX t_v_unique ON t (v)');

        return $ops;
    }

    #[Test]
    public function query_returns_assoc_rows_with_native_types(): void
    {
        $ops = $this->ops();
        $ops->execute('INSERT INTO t (v, n, f) VALUES (?, ?, ?)', ['a', 5, 1.25]);

        $rows = $ops->query('SELECT v, n, f, NULL AS missing FROM t');

        $this->assertSame(
            [['v' => 'a', 'n' => 5, 'f' => 1.25, 'missing' => null]],
            $rows
        );
    }

    #[Test]
    public function no_rowset_statements_through_query_return_an_empty_array(): void
    {
        $ops = $this->ops();

        $this->assertSame([], $ops->query('INSERT INTO t (v) VALUES (?)', ['x']));
    }

    #[Test]
    public function execute_returns_affected_rows_and_last_insert_id(): void
    {
        $ops = $this->ops();

        $result = $ops->execute('INSERT INTO t (v) VALUES (?)', ['a']);
        $this->assertSame(['affected_rows' => 1, 'last_insert_id' => 1], $result);

        $ops->execute('INSERT INTO t (v) VALUES (?)', ['b']);
        $result = $ops->execute('UPDATE t SET n = ?', [7]);
        $this->assertSame(2, $result['affected_rows']);
    }

    #[Test]
    public function bool_and_null_params_bind(): void
    {
        $ops = $this->ops();
        $ops->execute('INSERT INTO t (v, n) VALUES (?, ?)', ['x', true]);
        $ops->execute('INSERT INTO t (v, n) VALUES (?, ?)', ['y', null]);

        $rows = $ops->query('SELECT v, n FROM t ORDER BY id');
        $this->assertSame([['v' => 'x', 'n' => 1], ['v' => 'y', 'n' => null]], $rows);
    }

    #[Test]
    public function unique_violation_maps_to_1062_with_sqlstate_23000(): void
    {
        $ops = $this->ops();
        $ops->execute('INSERT INTO t (v) VALUES (?)', ['dup']);

        try {
            $ops->execute('INSERT INTO t (v) VALUES (?)', ['dup']);
            $this->fail('expected duplicate-key exception');
        } catch (\Exception $e) {
            $this->assertSame(1062, (int) $e->getCode());
            $this->assertStringStartsWith('SQLSTATE[23000]: ', $e->getMessage());
            $this->assertStringContainsString('UNIQUE constraint failed', $e->getMessage());
        }
    }

    #[Test]
    public function unclassified_errors_fall_back_to_1105_hy000(): void
    {
        $ops = $this->ops();

        try {
            $ops->query('SELECT * FROM missing_table');
            $this->fail('expected exception');
        } catch (\Exception $e) {
            $this->assertSame(1105, (int) $e->getCode());
            $this->assertStringStartsWith('SQLSTATE[HY000]: ', $e->getMessage());
        }
    }

    #[Test]
    public function transaction_statements_flow_through_as_plain_sql(): void
    {
        $ops = $this->ops();

        $ops->execute('BEGIN');
        $ops->execute('INSERT INTO t (v) VALUES (?)', ['kept']);
        $ops->execute('SAVEPOINT trans2');
        $ops->execute('INSERT INTO t (v) VALUES (?)', ['discarded']);
        $ops->execute('ROLLBACK TO SAVEPOINT trans2');
        $ops->execute('COMMIT');

        $this->assertSame([['v' => 'kept']], $ops->query('SELECT v FROM t'));
    }

    #[Test]
    public function non_scalar_params_throw_the_wrapper_error(): void
    {
        $ops = $this->ops();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/unsupported parameter type array/');

        $ops->query('SELECT ?', [[1, 2]]);
    }
}
