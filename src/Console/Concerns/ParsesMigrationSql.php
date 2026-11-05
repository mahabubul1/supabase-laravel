<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Console\Concerns;

trait ParsesMigrationSql
{
    /**
     * Splits a migration file's contents on its `-- up` / `-- down` marker
     * lines. Files without markers are treated as up-only (no down section),
     * matching plain .sql files written before this convention existed.
     *
     * @return array{up: string, down: ?string}
     */
    protected function parseMigrationSql(string $contents): array
    {
        $upPos = stripos($contents, '-- up');

        if ($upPos === false) {
            return ['up' => trim($contents), 'down' => null];
        }

        $downPos = stripos($contents, '-- down', $upPos);

        if ($downPos === false) {
            return ['up' => trim(substr($contents, $upPos + 5)), 'down' => null];
        }

        $up = trim(substr($contents, $upPos + 5, $downPos - $upPos - 5));
        $down = trim(substr($contents, $downPos + 7));

        return ['up' => $up, 'down' => $down !== '' ? $down : null];
    }

    /**
     * True if the given SQL has no executable statements once `--` line
     * comments are stripped — e.g. an unfilled `-- TODO: ...` stub.
     */
    protected function isBlankSql(string $sql): bool
    {
        $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql);

        return trim((string) $withoutComments) === '';
    }
}
