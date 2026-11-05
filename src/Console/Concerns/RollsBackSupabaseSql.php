<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Console\Concerns;

use PDOException;

/**
 * Shared rollback logic for commands that restore a previous version of a
 * .sql file pushed by a PushesSupabaseSql command.
 *
 * Expects the host command to also `use ConnectsToSupabasePostgres` and to
 * define `--connection` and `--dry-run` options plus a single argument
 * holding the file path, basename, or bare name to roll back.
 */
trait RollsBackSupabaseSql
{
    protected function rollbackSql(string $manifestPrefix, string $argument, string $noun): int
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

        $needle = (string) $this->argument($argument);

        $historyPath = storage_path("framework/{$manifestPrefix}-history-{$connectionName}.json");
        $history = $this->loadJson($historyPath);

        $key = $this->resolveHistoryKey($history, $needle);

        if ($key === null) {
            $this->components->error("No push history found for \"{$needle}\".");

            return self::FAILURE;
        }

        $versions = $history[$key];

        if (! isset($versions[1])) {
            $this->components->error("No previous version to roll back to for {$key}.");

            return self::FAILURE;
        }

        $target = $versions[1];

        if ($this->option('dry-run')) {
            $this->components->info("Would restore {$key} to the version pushed at {$target['pushed_at']}:");
            $this->line($target['sql']);

            return self::SUCCESS;
        }

        try {
            $pdo = $this->connect($dbUrl);
        } catch (PDOException $exception) {
            $this->components->error("Could not connect to the database: {$exception->getMessage()}");

            return self::FAILURE;
        }

        try {
            $pdo->exec($target['sql']);
        } catch (PDOException $exception) {
            $this->components->error("Failed applying rollback for {$key}: {$exception->getMessage()}");

            return self::FAILURE;
        }

        array_shift($versions);
        $history[$key] = $versions;
        $this->saveJson($historyPath, $history);

        $manifestPath = storage_path("framework/{$manifestPrefix}-{$connectionName}.json");
        $manifest = $this->loadJson($manifestPath);
        $manifest[$key] = $target['hash'];
        $this->saveJson($manifestPath, $manifest);

        $this->components->info("Rolled back {$key} to the version pushed at {$target['pushed_at']}.");

        return self::SUCCESS;
    }

    /**
     * Resolves the given argument to a history key. Accepts, in order: an
     * exact key match, a file basename match (with or without ".sql"), or a
     * bare name match (the file's basename without ".sql").
     *
     * @param  array<string, mixed>  $history
     */
    protected function resolveHistoryKey(array $history, string $needle): ?string
    {
        if (isset($history[$needle])) {
            return $needle;
        }

        $needleName = preg_replace('/\.sql$/', '', basename($needle));

        foreach (array_keys($history) as $key) {
            $keyName = preg_replace('/\.sql$/', '', basename((string) $key));

            if ($keyName === $needleName) {
                return (string) $key;
            }
        }

        return null;
    }
}
