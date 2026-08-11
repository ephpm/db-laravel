<?php

declare(strict_types=1);

namespace Ephpm\Db\Laravel\Tests;

use DateTime;
use Ephpm\Db\Laravel\EphpmConnection;
use Ephpm\Db\Laravel\SqlitePdoDbOps;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EphpmConnectionTest extends TestCase
{
    private function connection(?RecordingOps &$recorder = null): EphpmConnection
    {
        $ops = new SqlitePdoDbOps();

        // Test tables are created in SQLite dialect directly on the
        // polyfill PDO — in production, migrations run MySQL DDL through
        // litewire's translator, which the polyfill does not implement.
        $ops->pdo()->exec(
            'CREATE TABLE users ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'name TEXT NOT NULL, '
            . 'email TEXT, '
            . 'visits INTEGER NOT NULL DEFAULT 0, '
            . 'score REAL, '
            . 'created_at TEXT)'
        );
        $ops->pdo()->exec('CREATE UNIQUE INDEX users_email_unique ON users (email)');

        $recorder = new RecordingOps($ops);

        return new EphpmConnection($recorder, 'main', '', [
            'driver' => 'ephpm',
            'name' => 'ephpm',
            'database' => 'main',
            'prefix' => '',
        ]);
    }

    #[Test]
    public function select_returns_arrays_of_std_class_with_native_types(): void
    {
        $db = $this->connection();
        $db->table('users')->insert([
            ['name' => 'Alice', 'email' => 'alice@example.com', 'visits' => 3, 'score' => 1.5],
            ['name' => 'Bob', 'email' => 'bob@example.com', 'visits' => 7, 'score' => 2.25],
        ]);

        $rows = $db->select('select * from `users` order by `id`');

        $this->assertCount(2, $rows);
        $this->assertInstanceOf(\stdClass::class, $rows[0]);
        $this->assertSame('Alice', $rows[0]->name);
        $this->assertSame(3, $rows[0]->visits);
        $this->assertSame(1.5, $rows[0]->score);
        $this->assertNull($rows[0]->created_at);
    }

    #[Test]
    public function query_builder_where_and_first_produce_expected_shapes(): void
    {
        $db = $this->connection();
        $db->table('users')->insert([
            ['name' => 'Alice', 'email' => 'alice@example.com', 'visits' => 3],
            ['name' => 'Bob', 'email' => 'bob@example.com', 'visits' => 7],
        ]);

        $busy = $db->table('users')->where('visits', '>', 5)->get();
        $this->assertCount(1, $busy);
        $this->assertSame('Bob', $busy->first()->name);

        $first = $db->table('users')->where('name', 'Alice')->first();
        $this->assertInstanceOf(\stdClass::class, $first);
        $this->assertSame('alice@example.com', $first->email);

        $this->assertNull($db->table('users')->where('name', 'Nobody')->first());
        $this->assertSame(2, $db->table('users')->count());
    }

    #[Test]
    public function insert_get_id_returns_the_auto_increment_id(): void
    {
        $db = $this->connection();

        $first = $db->table('users')->insertGetId(['name' => 'Alice', 'email' => 'a@example.com']);
        $second = $db->table('users')->insertGetId(['name' => 'Bob', 'email' => 'b@example.com']);

        $this->assertSame(1, $first);
        $this->assertSame(2, $second);
    }

    #[Test]
    public function raw_last_insert_id_survives_non_insert_statements(): void
    {
        $db = $this->connection();

        $this->assertTrue($db->table('users')->insert(['name' => 'Alice', 'email' => 'a@example.com']));
        $this->assertSame(1, $db->getRawLastInsertId());

        // Mirrors MySQL LAST_INSERT_ID(): an UPDATE does not clear it.
        $db->table('users')->where('id', 1)->update(['visits' => 9]);
        $this->assertSame(1, $db->getRawLastInsertId());
    }

    #[Test]
    public function update_and_delete_report_affected_row_counts(): void
    {
        $db = $this->connection();
        $db->table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@example.com', 'visits' => 1],
            ['name' => 'Bob', 'email' => 'b@example.com', 'visits' => 1],
            ['name' => 'Cara', 'email' => 'c@example.com', 'visits' => 5],
        ]);

        $this->assertSame(2, $db->table('users')->where('visits', 1)->update(['visits' => 2]));
        $this->assertSame(0, $db->table('users')->where('visits', 99)->update(['visits' => 1]));
        $this->assertSame(1, $db->table('users')->where('name', 'Cara')->delete());
        $this->assertSame(2, $db->table('users')->count());
    }

    #[Test]
    public function transaction_commits_on_success(): void
    {
        $db = $this->connection($ops);

        $result = $db->transaction(function (EphpmConnection $db): string {
            $db->table('users')->insert(['name' => 'Alice', 'email' => 'a@example.com']);

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(0, $db->transactionLevel());
        $this->assertSame(1, $db->table('users')->count());
        $this->assertContains('BEGIN', $ops->executed);
        $this->assertContains('COMMIT', $ops->executed);
    }

    #[Test]
    public function transaction_rolls_back_and_rethrows_on_failure(): void
    {
        $db = $this->connection($ops);

        try {
            $db->transaction(function (EphpmConnection $db): void {
                $db->table('users')->insert(['name' => 'Alice', 'email' => 'a@example.com']);

                throw new \DomainException('boom');
            });
            $this->fail('expected the callback exception to be rethrown');
        } catch (\DomainException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, $db->transactionLevel());
        $this->assertSame(0, $db->table('users')->count());
        $this->assertContains('ROLLBACK', $ops->executed);
    }

    #[Test]
    public function nested_transactions_use_savepoints_with_litewire_compatible_syntax(): void
    {
        $db = $this->connection($ops);

        $db->beginTransaction();
        $db->table('users')->insert(['name' => 'Outer', 'email' => 'outer@example.com']);

        $db->beginTransaction();
        $this->assertSame(2, $db->transactionLevel());
        $db->table('users')->insert(['name' => 'Inner', 'email' => 'inner@example.com']);
        $db->rollBack();

        $db->commit();

        $this->assertSame(0, $db->transactionLevel());
        $this->assertSame(['Outer'], $db->table('users')->pluck('name')->all());

        // Exactly the statements litewire's MySQL-dialect translator
        // passes through (see litewire-translate SAVEPOINT tests).
        $this->assertContains('SAVEPOINT trans2', $ops->executed);
        $this->assertContains('ROLLBACK TO SAVEPOINT trans2', $ops->executed);
    }

    #[Test]
    public function query_exception_carries_sql_bindings_and_backend_errno(): void
    {
        $db = $this->connection();

        try {
            $db->select('select * from `missing_table` where `id` = ?', [42]);
            $this->fail('expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('select * from `missing_table` where `id` = ?', $e->getSql());
            $this->assertSame([42], $e->getBindings());
            // litewire maps unclassified backend errors (including missing
            // tables) to ER_UNKNOWN_ERROR 1105 / HY000.
            $this->assertSame(1105, (int) $e->getCode());
            $this->assertStringContainsString('SQLSTATE[HY000]', $e->getMessage());
        }
    }

    #[Test]
    public function duplicate_key_surfaces_as_unique_constraint_violation_with_errno_1062(): void
    {
        $db = $this->connection();
        $db->table('users')->insert(['name' => 'Alice', 'email' => 'same@example.com']);

        try {
            $db->table('users')->insert(['name' => 'Clone', 'email' => 'same@example.com']);
            $this->fail('expected UniqueConstraintViolationException');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertSame(1062, (int) $e->getCode());
            $this->assertStringContainsString('SQLSTATE[23000]', $e->getMessage());
            $this->assertStringContainsString('insert into `users`', $e->getSql());
            $this->assertSame(['Clone', 'same@example.com'], $e->getBindings());
        }
    }

    #[Test]
    public function date_time_and_bool_bindings_are_normalized_by_prepare_bindings(): void
    {
        $db = $this->connection();

        $db->table('users')->insert([
            'name' => 'Alice',
            'email' => 'a@example.com',
            'visits' => true,
            'created_at' => new DateTime('2026-08-10 12:34:56'),
        ]);

        $row = $db->table('users')->first();
        $this->assertSame(1, $row->visits);
        $this->assertSame('2026-08-10 12:34:56', $row->created_at);
    }

    #[Test]
    public function non_scalar_bindings_are_rejected_before_reaching_the_bridge(): void
    {
        $db = $this->connection($ops);

        try {
            $db->statement('insert into `users` (`name`) values (?)', [['not', 'scalar']]);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('unsupported binding of type array', $e->getMessage());
        }

        // The bad statement never reached the bridge.
        $this->assertSame([], $ops->executed);
    }

    #[Test]
    public function object_bindings_without_conversion_are_rejected_too(): void
    {
        $db = $this->connection();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unsupported binding of type SplStack/');

        $db->select('select * from `users` where `name` = ?', [new \SplStack()]);
    }

    #[Test]
    public function schema_builder_emits_mysql_shaped_ddl(): void
    {
        $db = $this->connection();

        // pretend() captures the compiled statements without executing
        // them — the polyfill's SQLite cannot parse MySQL DDL, but in
        // production this exact SQL goes through litewire's translator.
        $log = $db->pretend(function () use ($db): void {
            $db->getSchemaBuilder()->create('widgets', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        });

        $sql = implode('; ', array_column($log, 'query'));

        $this->assertStringContainsString('create table `widgets`', $sql);
        $this->assertStringContainsString('`id` bigint unsigned not null auto_increment primary key', $sql);
        $this->assertStringContainsString('`name` varchar(255) not null', $sql);
        $this->assertStringContainsString("`active` tinyint(1) not null default '1'", $sql);
        $this->assertStringContainsString('`created_at` timestamp null', $sql);
    }

    #[Test]
    public function cursor_yields_std_class_rows(): void
    {
        $db = $this->connection();
        $db->table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@example.com'],
            ['name' => 'Bob', 'email' => 'b@example.com'],
        ]);

        $names = [];
        foreach ($db->table('users')->orderBy('id')->cursor() as $row) {
            $this->assertInstanceOf(\stdClass::class, $row);
            $names[] = $row->name;
        }

        $this->assertSame(['Alice', 'Bob'], $names);
    }

    #[Test]
    public function unprepared_statements_run_through_the_bridge(): void
    {
        $db = $this->connection($ops);

        $this->assertTrue($db->unprepared("insert into `users` (`name`) values ('Raw')"));
        $this->assertSame(1, $db->table('users')->count());
        $this->assertContains("insert into `users` (`name`) values ('Raw')", $ops->executed);
    }

    #[Test]
    public function select_result_sets_is_unsupported(): void
    {
        $db = $this->connection();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/single result set/');

        $db->selectResultSets('select 1; select 2');
    }

    #[Test]
    public function pretend_logs_queries_without_touching_the_bridge(): void
    {
        $db = $this->connection($ops);

        $log = $db->pretend(function () use ($db): void {
            $db->table('users')->insert(['name' => 'Ghost', 'email' => 'g@example.com']);
        });

        $this->assertCount(1, $log);
        $this->assertSame([], $ops->executed);
        $this->assertSame(0, $db->table('users')->count());
    }
}
