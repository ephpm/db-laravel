<?php

declare(strict_types=1);

namespace Ephpm\Db\Laravel\Tests;

use Ephpm\Db\Laravel\EphpmConnection;
use Ephpm\Db\Laravel\EphpmDbServiceProvider;
use Ephpm\Db\Laravel\SqlitePdoDbOps;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EphpmDbServiceProviderTest extends TestCase
{
    #[Test]
    public function register_installs_a_resolver_for_the_ephpm_driver(): void
    {
        (new EphpmDbServiceProvider(new Container()))->register();

        $this->assertIsCallable(Connection::getResolver('ephpm'));
    }

    #[Test]
    public function the_connection_factory_builds_an_ephpm_connection(): void
    {
        (new EphpmDbServiceProvider(new Container()))->register();

        $ops = new SqlitePdoDbOps();

        $connection = (new ConnectionFactory(new Container()))->make([
            'driver' => 'ephpm',
            'database' => 'main',
            'prefix' => '',
            'ops' => $ops,
        ], 'ephpm');

        $this->assertInstanceOf(EphpmConnection::class, $connection);
        $this->assertSame($ops, $connection->getOps());
        $this->assertSame('main', $connection->getDatabaseName());

        // Round-trip through the factory-built connection.
        $ops->pdo()->exec('CREATE TABLE things (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $id = $connection->table('things')->insertGetId(['name' => 'widget']);
        $this->assertSame(1, $id);
        $this->assertSame('widget', $connection->table('things')->first()->name);
    }
}
