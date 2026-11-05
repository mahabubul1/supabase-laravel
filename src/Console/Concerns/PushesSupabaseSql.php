<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Console\Concerns;

use PDOException;

/**
 * Shared push logic for commands that apply .sql files directly against
 * Supabase's Postgres instance (functions, tables, ...), skipping files
 * whose contents haven't changed since the last successful push.
 *
 * Expects the host command to also `use ConnectsToSupabasePostgres` and to
 * define `--connection`, `--path`, `--force`, and `--dry-run` options.
 */
trait PushesSupabaseSql
{
    /**
     * Number of previous versions kept per file for the matching rollback command.
     */
    public const HISTORY_LIMIT = 10;

    protected function pushSqlDirectory(string $manifestPrefix, string $defaultDirectory, string $noun, ?string $only = null): int
    {
        $connectionName = (string) ($this->option('connection') ?: config('supabase.default'));
        $directory = rtrim((string) ($this->option('path') ?: $defaultDirectory), '/');
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

        if ($only !== null) {
            $wanted = preg_replace('/\.sql$/', '', basename($only));
            $files = array_values(array_filter(
                $files,
                fn (string $file): bool => preg_replace('/\.sql$/', '', basename($file)) === $wanted,
            ));

            if ($files === []) {
                $this->components->error("No such file for \"{$only}\" in {$directory}.");

                return self::FAILURE;
            }
        }

        if ($files === []) {
            $this->components->warn("No .sql files found in {$directory}.");

            return self::SUCCESS;
        }

        sort($files);

        $manifestPath = storage_path("framework/{$manifestPrefix}-{$connectionName}.json");
        $manifest = $this->loadJson($manifestPath);

        $pending = [];

        foreach ($files as $file) {
            $hash = hash('sha256', (string) file_get_contents($file));

            if ($this->option('force') || ($manifest[$file] ?? null) !== $hash) {
                $pending[$file] = $hash;
            }
        }

        if ($pending === []) {
            $this->components->info("Nothing to push — all {$noun}s match their last-pushed contents.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info('Would push:');
            foreach (array_keys($pending) as $file) {
                $this->line("  - {$file}");
            }

            return self::SUCCESS;
        }

        try {
            $pdo = $this->connect($dbUrl);
        } catch (PDOException $exception) {
            $this->components->error("Could not connect to the database: {$exception->getMessage()}");

            return self::FAILURE;
        }

        foreach ($pending as $file => $hash) {
            $sql = (string) file_get_contents($file);

            try {
                $pdo->exec($sql);
            } catch (PDOException $exception) {
                $this->components->error("Failed applying {$file}: {$exception->getMessage()}");

                return self::FAILURE;
            }

            $this->recordSqlHistory(storage_path("framework/{$manifestPrefix}-history-{$connectionName}.json"), $file, $hash, $sql);

            $manifest[$file] = $hash;
            $this->components->info("Pushed {$file}");
        }

        $this->saveJson($manifestPath, $manifest);

        $this->components->info(sprintf('Pushed %d %s(s).', count($pending), $noun));

        return self::SUCCESS;
    }

    /**
     * Prepends the just-pushed version to that file's history stack, so the
     * matching rollback command can restore it later. Index 0 is always the
     * version currently applied to the database.
     */
    protected function recordSqlHistory(string $path, string $file, string $hash, string $sql): void
    {
        $history = $this->loadJson($path);
        $entries = is_array($history[$file] ?? null) ? $history[$file] : [];

        array_unshift($entries, [
            'hash' => $hash,
            'sql' => $sql,
            'pushed_at' => now()->toIso8601String(),
        ]);

        $history[$file] = array_slice($entries, 0, self::HISTORY_LIMIT);

        $this->saveJson($path, $history);
    }
}
