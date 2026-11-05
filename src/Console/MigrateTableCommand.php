<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Console;

use BlackDevs\SupabaseLaravel\Console\Concerns\ConnectsToSupabasePostgres;
use BlackDevs\SupabaseLaravel\Console\Concerns\ParsesMigrationSql;
use Illuminate\Console\Command;
use PDOException;

/**
 * Applies pending .sql migration files scaffolded by `supabase:make-table`,
 * in filename (timestamp) order, tracking which have already run and which
 * batch they ran in — mirroring Laravel's own migrator, but against
 * Supabase's Postgres instance directly since PostgREST cannot run DDL.
 */
class MigrateTableCommand extends Command
{
    use ConnectsToSupabasePostgres, ParsesMigrationSql;

    protected $signature = 'supabase:migrate
        {table? : Only run pending migrations whose filename contains this name (e.g. a migration name or table name)}
        {--table= : Same filter as {table}, as a named option — e.g. --table=users}
        {--connection= : The config/supabase.php connection to migrate (default: config default)}
        {--path= : Directory of .sql migration files (default: database/supabase/tables)}
        {--dry-run : List which migrations would run without applying them}';

    protected $description = 'Run pending Postgres table migrations directly against Supabase';

    public function handle(): int
    {
        $connectionName = (string) ($this->option('connection') ?: config('supabase.default'));
        $directory = rtrim((string) ($this->option('path') ?: base_path('database/supabase/tables')), '/');
        $dbUrl = config("supabase.connections.{$connectionName}.db_url");

        if (! $this->option('dry-run') && (! is_string($dbUrl) || $dbUrl === '')) {
            $this->components->error(
                "No db_url configured for the [{$connectionName}] connection. Set SUPABASE_DB_URL to the ".
                'Postgres connection string from Project Settings > Database (Session or Transaction pooler URI).',
            );

            return self::FAILURE;
        }

        if (! is_dir($directory)) {
            $this->components->error("No such directory: {$directory}");

            return self::FAILURE;
        }

        $files = glob("{$directory}/*.sql") ?: [];
        sort($files);

        $manifestPath = storage_path("framework/supabase-migrations-{$connectionName}.json");
        $manifest = $this->loadJson($manifestPath);
        $applied = $manifest['migrations'] ?? [];
        $appliedFiles = array_column($applied, 'file');

        $pending = array_values(array_filter($files, fn (string $file): bool => ! in_array(basename($file), $appliedFiles, true)));

        $filters = array_filter([$this->argument('table'), $this->option('table')], fn ($value) => $value !== null);

        foreach ($filters as $filter) {
            $pending = array_values(array_filter(
                $pending,
                fn (string $file): bool => str_contains(basename($file, '.sql'), (string) $filter),
            ));
        }

        if ($filters !== [] && $pending === []) {
            $this->components->error('No pending migration matches "'.implode('", "', $filters).'".');

            return self::FAILURE;
        }

        if ($pending === []) {
            $this->components->info('Nothing to migrate — no pending migration files.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info('Would migrate:');
            foreach ($pending as $file) {
                $sections = $this->parseMigrationSql((string) file_get_contents($file));
                $suffix = $this->isBlankSql($sections['up']) ? ' (empty "-- up" section — would be skipped)' : '';
                $this->line('  - '.basename($file).$suffix);
            }

            return self::SUCCESS;
        }

        try {
            $pdo = $this->connect($dbUrl);
        } catch (PDOException $exception) {
            $this->components->error("Could not connect to the database: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $batch = $applied === [] ? 1 : max(array_column($applied, 'batch')) + 1;

        $migrated = 0;

        foreach ($pending as $file) {
            $sections = $this->parseMigrationSql((string) file_get_contents($file));

            if ($this->isBlankSql($sections['up'])) {
                $this->components->warn('Skipped (empty "-- up" section, fill it in and re-run): '.basename($file));

                continue;
            }

            try {
                $pdo->exec($sections['up']);
            } catch (PDOException $exception) {
                $this->components->error("Failed applying {$file}: {$exception->getMessage()}");

                return self::FAILURE;
            }

            $applied[] = [
                'file' => basename($file),
                'up' => $sections['up'],
                'down' => $sections['down'],
                'batch' => $batch,
                'applied_at' => now()->toIso8601String(),
            ];

            $migrated++;
            $this->components->info('Migrated: '.basename($file));
        }

        $manifest['migrations'] = $applied;
        $this->saveJson($manifestPath, $manifest);

        $this->components->info(sprintf('Migrated %d table migration(s).', $migrated));

        return self::SUCCESS;
    }
}
