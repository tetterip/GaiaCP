<?php

namespace Plugins\WhmcsImport;

use Illuminate\Support\Facades\DB;

/** The "whmcs" database connection (the plugin's config.php, WHMCS_DB_* in .env), with values given at run time on top. */
class WhmcsConnection
{
    public const NAME = 'whmcs';

    /** @param  array{host?: string, port?: int|string, database?: string, username?: string, password?: string}  $values  blank ones keep the .env value */
    public static function configure(array $values): void
    {
        $values = array_filter($values, fn ($value) => filled($value));

        config(['database.connections.'.self::NAME => [...config('database.connections.'.self::NAME), ...$values]]);
        DB::purge(self::NAME);
    }
}
