<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Console;

use BlackDevs\SupabaseLaravel\Console\Concerns\ConnectsToSupabasePostgres;
use BlackDevs\SupabaseLaravel\Console\Concerns\PushesSupabaseSql;
use Illuminate\Console\Command;

/**
 * Applies .sql files scaffolded by `supabase:make-function` directly against
 * the project's Postgres database, skipping files whose contents haven't
 * changed since the last successful push.
 *
 * PostgREST cannot run DDL, so this connects directly via `db_url`
 * (config('supabase.connections.{connection}.db_url')) instead of going
 * through the Supabase facade/HTTP client like the rest of this package.
 */
class PushFunctionsCommand extends Command
{
    use ConnectsToSupabasePostgres, PushesSupabaseSql;

    protected $signature = 'supabase:push-functions
        {--connection= : The config/supabase.php connection to push to (default: config default)}
        {--path= : Directory of .sql files to push (default: database/supabase/functions)}
        {--force : Push every file regardless of whether its contents changed}
        {--dry-run : List which files would be pushed without applying them}';

    protected $description = 'Push changed Postgres function .sql files directly to Supabase';

    public function handle(): int
    {
        return $this->pushSqlDirectory('supabase-functions', base_path('database/supabase/functions'), 'function');
    }
}
