<?php

declare(strict_types=1);

namespace Ephpm\Db\Laravel;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovered Laravel service provider that registers the `ephpm`
 * database driver. Apps then add a connection under
 * `config/database.php`:
 *
 *   'connections' => [
 *       'ephpm' => [
 *           'driver'   => 'ephpm',
 *           'database' => 'main',
 *           'prefix'   => '',
 *       ],
 *   ],
 *
 * and select it with `DB_CONNECTION=ephpm`.
 *
 * Registration uses Connection::resolverFor(), the hook Laravel's
 * ConnectionFactory consults before its built-in driver switch. The
 * factory still passes its lazy PDO-resolver closure as the first
 * argument; we ignore it — it would only try (and fail) to create a real
 * PDO if invoked, and it never is.
 */
final class EphpmDbServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // resolverFor() is a static registry on Connection itself — safe in
        // register(), no container services required.
        Connection::resolverFor(
            'ephpm',
            static function ($connection, $database, $prefix, array $config): EphpmConnection {
                // An app (or test) may inject its own backend via the
                // connection config; production resolves the SAPI natives.
                $ops = $config['ops'] ?? null;

                if (!$ops instanceof DbOpsInterface) {
                    $ops = new SapiDbOps();
                }

                return new EphpmConnection($ops, (string) $database, (string) $prefix, $config);
            }
        );
    }
}
