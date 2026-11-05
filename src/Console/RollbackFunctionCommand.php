<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Console;

use BlackDevs\SupabaseLaravel\Console\Concerns\ConnectsToSupabasePostgres;
use BlackDevs\SupabaseLaravel\Console\Concerns\RollsBackSupabaseSql;
use Illuminate\Console\Command;

/**
 * Restores the previous version of a function pushed by `supabase:push-functions`.
 *
 * Each successful push prepends the applied SQL to a per-file history stack
 * (index 0 = currently applied version). Rolling back applies index 1 to the
 * database, then drops index 0 so a repeated rollback walks further back, up
 * to PushesSupabaseSql::HISTORY_LIMIT versions.
 */
class RollbackFunctionCommand extends Command
{
    use ConnectsToSupabasePostgres, RollsBackSupabaseSql;

    protected $signature = 'supabase:rollback-function
        {function : Function name (e.g. get_users), or the .sql file path/basename used with supabase:push-functions}
        {--connection= : The config/supabase.php connection to roll back (default: config default)}
        {--dry-run : Show the SQL that would be applied without running it}';

    protected $description = 'Restore the previous version of a function pushed via supabase:push-functions';

    public function handle(): int
    {
        return $this->rollbackSql('supabase-functions', 'function', 'function');
    }
}
