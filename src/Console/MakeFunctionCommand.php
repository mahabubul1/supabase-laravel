<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Scaffolds a .sql file for a Postgres function to be applied through
 * Supabase's own migration tooling (SQL editor / Supabase CLI) — this is
 * NOT a Laravel migration, since it targets Supabase's Postgres instance
 * rather than this app's local database connection.
 */
class MakeFunctionCommand extends Command
{
    protected $signature = 'supabase:make-function {name : The Postgres function name, e.g. calculate_total}
        {--path= : Directory to write the .sql file into (default: database/supabase/functions)}
        {--force : Overwrite the file if it already exists}';

    protected $description = "Scaffold a .sql file for a Postgres function to run through Supabase's own migration tooling";

    public function handle(): int
    {
        $name = Str::snake((string) $this->argument('name'));
        $directory = rtrim((string) ($this->option('path') ?: base_path('database/supabase/functions')), '/');
        $path = "{$directory}/{$name}.sql";

        if (file_exists($path) && ! $this->option('force')) {
            $this->components->error("{$path} already exists. Pass --force to overwrite.");

            return self::FAILURE;
        }

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->components->error("Could not create directory {$directory}.");

            return self::FAILURE;
        }

        file_put_contents($path, $this->stub($name));

        $this->components->info("Created {$path}");
        $this->components->warn(
            'This file is not run automatically — apply it via the Supabase SQL editor, '.
            '`php artisan supabase:push-functions`, or `supabase db push` (Supabase CLI), then call it with '.
            "Supabase::rpc('{$name}', [...]).",
        );

        return self::SUCCESS;
    }

    protected function stub(string $name): string
    {
        return <<<SQL
-- Postgres function: {$name}
--
-- Apply this via the Supabase SQL editor or `supabase db push` (Supabase
-- CLI) — it is NOT run by Laravel's migrator, since it targets Supabase's
-- Postgres instance rather than this app's local database connection.
--
-- Call it from Laravel with:
--   Supabase::rpc('{$name}', ['param' => \$value]);

create or replace function public.{$name}(/* add parameters here, e.g. param text */)
returns void
language plpgsql
security definer
set search_path = public
as \$\$
begin
    -- TODO: implement {$name}
end;
\$\$;

SQL;
    }
}
