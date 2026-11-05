<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Console;

use BlackDevs\SupabaseLaravel\Console\Concerns\ConnectsToSupabasePostgres;
use Illuminate\Console\Command;
use PDOException;
use Throwable;

/**
 * Runs seeders scaffolded by `supabase:make-seeder`.
 *
 * .sql seeders (raw INSERT statements) run first, applied directly via
 * `db_url` since they bypass PostgREST. .php class seeders run after,
 * resolved through the container and invoked via their `run()` method,
 * seeding through PostgREST via the `Supabase` facade.
 *
 * Unlike `supabase:migrate`, seeders are not tracked as "applied" — like
 * Laravel's own `db:seed`, running this again re-runs every seeder, so
 * seeders should be written to be safe to repeat (e.g. upsert, or truncate
 * first).
 */
class SeedCommand extends Command
{
    use ConnectsToSupabasePostgres;

    protected $signature = 'supabase:seed
        {name? : Only run seeders whose filename/class name contains this}
        {--connection= : The config/supabase.php connection to use for .sql seeders (default: config default)}
        {--path= : Directory of seeders (default: database/supabase/seeders)}
        {--dry-run : List which seeders would run without applying them}';

    protected $description = 'Run seeders scaffolded by supabase:make-seeder';

    public function handle(): int
    {
        $directory = rtrim((string) ($this->option('path') ?: base_path('database/supabase/seeders')), '/');

        if (! is_dir($directory)) {
            $this->components->error("No such directory: {$directory}");

            return self::FAILURE;
        }

        $name = $this->argument('name');
        $matches = fn (string $file) => $name === null || str_contains(
            strtolower(basename($file, '.'.pathinfo($file, PATHINFO_EXTENSION))),
            strtolower($name),
        );

        $sqlFiles = array_values(array_filter(glob("{$directory}/*.sql") ?: [], $matches));
        $phpFiles = array_values(array_filter(glob("{$directory}/*.php") ?: [], $matches));
        sort($sqlFiles);
        sort($phpFiles);

        if ($sqlFiles === [] && $phpFiles === []) {
            $this->components->info($name === null ? 'No seeders found.' : "No seeders match \"{$name}\".");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info('Would seed:');
            foreach ([...$sqlFiles, ...$phpFiles] as $file) {
                $this->line('  - '.basename($file));
            }

            return self::SUCCESS;
        }

        if ($sqlFiles !== [] && ! $this->runSqlSeeders($sqlFiles)) {
            return self::FAILURE;
        }

        if ($phpFiles !== [] && ! $this->runPhpSeeders($phpFiles)) {
            return self::FAILURE;
        }

        $this->components->info(sprintf('Seeded %d file(s).', count($sqlFiles) + count($phpFiles)));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $files
     */
    protected function runSqlSeeders(array $files): bool
    {
        $connectionName = (string) ($this->option('connection') ?: config('supabase.default'));
        $dbUrl = config("supabase.connections.{$connectionName}.db_url");

        if (! is_string($dbUrl) || $dbUrl === '') {
            $this->components->error(
                "No db_url configured for the [{$connectionName}] connection. Set SUPABASE_DB_URL to the ".
                'Postgres connection string from Project Settings > Database (Session or Transaction pooler URI).',
            );

            return false;
        }

        try {
            $pdo = $this->connect($dbUrl);
        } catch (PDOException $exception) {
            $this->components->error("Could not connect to the database: {$exception->getMessage()}");

            return false;
        }

        foreach ($files as $file) {
            try {
                $pdo->exec((string) file_get_contents($file));
            } catch (PDOException $exception) {
                $this->components->error("Failed seeding {$file}: {$exception->getMessage()}");

                return false;
            }

            $this->components->info('Seeded: '.basename($file));
        }

        return true;
    }

    /**
     * @param  array<int, string>  $files
     */
    protected function runPhpSeeders(array $files): bool
    {
        foreach ($files as $file) {
            $class = $this->classFromFile($file);

            if ($class === null || ! class_exists($class)) {
                $this->components->error("Could not resolve a class from {$file}.");

                return false;
            }

            $seeder = app()->make($class);

            if (! method_exists($seeder, 'run')) {
                $this->components->error("{$class} has no run() method.");

                return false;
            }

            try {
                app()->call([$seeder, 'run']);
            } catch (Throwable $exception) {
                $this->components->error("Failed running {$class}: {$exception->getMessage()}");

                return false;
            }

            $this->components->info('Seeded: '.basename($file));
        }

        return true;
    }

    protected function classFromFile(string $file): ?string
    {
        require_once $file;

        $contents = (string) file_get_contents($file);

        if (preg_match('/namespace\s+([^;]+);/', $contents, $namespaceMatch) !== 1) {
            return null;
        }

        if (preg_match('/class\s+(\w+)/', $contents, $classMatch) !== 1) {
            return null;
        }

        return trim($namespaceMatch[1]).'\\'.$classMatch[1];
    }
}
