<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Console;

use BlackDevs\SupabaseLaravel\Console\Concerns\ConnectsToSupabasePostgres;
use Illuminate\Console\Command;
use PDOException;

/**
 * Reverses migrations applied by `supabase:migrate`, using each file's
 * `-- down` section — mirroring `php artisan migrate:rollback`.
 *
 * With no {name}, rolls back the last `--step` batch(es) (newest applied
 * first, like Laravel). With {name}, rolls back only the most recently
 * applied migration whose filename contains it, regardless of batch.
 */
class RollbackTableCommand extends Command
{
    use ConnectsToSupabasePostgres;

    protected $signature = 'supabase:rollback-table
        {name? : Roll back only the most recent migration whose filename contains this (default: roll back the last batch)}
        {--step=1 : Number of batches to roll back when {name} is omitted}
        {--connection= : The config/supabase.php connection to roll back (default: config default)}
        {--dry-run : Show the SQL that would be applied without running it}';

    protected $description = 'Reverse table migrations applied via supabase:migrate';

    public function handle(): int
    {
        $connectionName = (string) ($this->option('connection') ?: config('supabase.default'));
        $dbUrl = config("supabase.connections.{$connectionName}.db_url");

        if (! $this->option('dry-run') && (! is_string($dbUrl) || $dbUrl === '')) {
            $this->components->error(
                "No db_url configured for the [{$connectionName}] connection. Set SUPABASE_DB_URL to the ".
                'Postgres connection string from Project Settings > Database (Session or Transaction pooler URI).',
            );

            return self::FAILURE;
        }

        $manifestPath = storage_path("framework/supabase-migrations-{$connectionName}.json");
        $manifest = $this->loadJson($manifestPath);
        $applied = $manifest['migrations'] ?? [];

        if ($applied === []) {
            $this->components->error('No migrations have been applied.');

            return self::FAILURE;
        }

        $targets = $this->selectTargets($applied, $this->argument('name'), (int) $this->option('step'));

        if ($targets === []) {
            $this->components->error('No matching applied migration found to roll back.');

            return self::FAILURE;
        }

        $missingDown = array_filter($targets, fn (array $target): bool => empty($target['down']));

        if ($missingDown !== []) {
            $files = implode(', ', array_column($missingDown, 'file'));
            $this->components->error("Cannot roll back — missing a \"-- down\" section for: {$files}.");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->components->info('Would roll back:');
            foreach ($targets as $target) {
                $this->line("  - {$target['file']}");
                $this->line($target['down']);
            }

            return self::SUCCESS;
        }

        try {
            $pdo = $this->connect($dbUrl);
        } catch (PDOException $exception) {
            $this->components->error("Could not connect to the database: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $rolledBackFiles = array_column($targets, 'file');

        foreach ($targets as $target) {
            try {
                $pdo->exec($target['down']);
            } catch (PDOException $exception) {
                $this->components->error("Failed rolling back {$target['file']}: {$exception->getMessage()}");

                return self::FAILURE;
            }

            $this->components->info('Rolled back: '.$target['file']);
        }

        $manifest['migrations'] = array_values(array_filter(
            $applied,
            fn (array $entry): bool => ! in_array($entry['file'], $rolledBackFiles, true),
        ));
        $this->saveJson($manifestPath, $manifest);

        $this->components->info(sprintf('Rolled back %d migration(s).', count($targets)));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{file: string, up: string, down: ?string, batch: int, applied_at: string}>  $applied
     * @return array<int, array{file: string, up: string, down: ?string, batch: int, applied_at: string}>
     */
    protected function selectTargets(array $applied, ?string $name, int $steps): array
    {
        if ($name !== null) {
            $matches = array_values(array_filter(
                $applied,
                fn (array $entry): bool => str_contains(pathinfo($entry['file'], PATHINFO_FILENAME), $name),
            ));

            if ($matches === []) {
                return [];
            }

            usort($matches, fn (array $a, array $b): int => $b['applied_at'] <=> $a['applied_at']);

            return [$matches[0]];
        }

        $batches = array_unique(array_column($applied, 'batch'));
        rsort($batches);
        $selectedBatches = array_slice($batches, 0, max($steps, 1));

        $targets = array_values(array_filter(
            $applied,
            fn (array $entry): bool => in_array($entry['batch'], $selectedBatches, true),
        ));

        usort($targets, fn (array $a, array $b): int => $b['applied_at'] <=> $a['applied_at']);

        return $targets;
    }
}
