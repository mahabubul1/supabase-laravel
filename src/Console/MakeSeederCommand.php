<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Scaffolds a seeder to be run through `supabase:seed`.
 *
 * By default this creates a PHP class (database/supabase/seeders/{Name}Seeder.php)
 * that seeds through PostgREST via the `Supabase` facade — same request path,
 * RLS/service-role rules, and validation as the rest of the package.
 *
 * With `--sql`, it instead scaffolds a timestamped .sql file (database/supabase/seeders/{timestamp}_{name}.sql)
 * of raw INSERT statements applied directly via `db_url`, bypassing PostgREST/RLS —
 * useful for seeding faster or before RLS policies are in place.
 */
class MakeSeederCommand extends Command
{
    protected $signature = 'supabase:make-seeder {name : Seeder name, e.g. invoices or InvoicesSeeder}
        {--sql : Scaffold a .sql seeder (raw INSERT statements via db_url) instead of a PHP class seeder}
        {--path= : Directory to write into (default: database/supabase/seeders)}';

    protected $description = 'Scaffold a seeder to run through supabase:seed';

    public function handle(): int
    {
        $directory = rtrim((string) ($this->option('path') ?: base_path('database/supabase/seeders')), '/');

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->components->error("Could not create directory {$directory}.");

            return self::FAILURE;
        }

        return $this->option('sql')
            ? $this->makeSqlSeeder((string) $this->argument('name'), $directory)
            : $this->makePhpSeeder((string) $this->argument('name'), $directory);
    }

    protected function makeSqlSeeder(string $name, string $directory): int
    {
        $table = Str::snake($name);
        $path = "{$directory}/".now()->format('Y_m_d_His')."_{$table}.sql";

        file_put_contents($path, <<<SQL
-- Seeder: {$table}
--
-- Apply with: php artisan supabase:seed
--
-- Raw INSERT statements, applied directly via db_url — bypasses
-- PostgREST/RLS, unlike a PHP seeder using the Supabase facade.

insert into public.{$table} (/* columns */) values
    (/* values */);

SQL);

        $this->components->info("Created {$path}");

        return self::SUCCESS;
    }

    protected function makePhpSeeder(string $name, string $directory): int
    {
        $className = Str::finish(Str::studly($name), 'Seeder');
        $table = Str::snake(Str::plural(Str::beforeLast($className, 'Seeder')));
        $path = "{$directory}/{$className}.php";

        if (file_exists($path)) {
            $this->components->error("{$path} already exists.");

            return self::FAILURE;
        }

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace Database\Supabase\Seeders;

use BlackDevs\SupabaseLaravel\Facades\Supabase;

/**
 * Run with: php artisan supabase:seed
 */
class {$className}
{
    public function run(): void
    {
        Supabase::table('{$table}')->insert([
            // ['column' => 'value'],
        ])->get();
    }
}

PHP);

        $this->components->info("Created {$path}");

        return self::SUCCESS;
    }
}
