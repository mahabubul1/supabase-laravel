<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Console\Concerns;

use PDO;
use PDOException;

trait ConnectsToSupabasePostgres
{
    protected function connect(string $dbUrl): PDO
    {
        $parts = parse_url($dbUrl);

        if ($parts === false || ! isset($parts['host'])) {
            throw new PDOException("Invalid db_url: {$dbUrl}");
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
            $parts['host'],
            $parts['port'] ?? 5432,
            ltrim($parts['path'] ?? '/postgres', '/'),
        );

        $pdo = new PDO(
            $dsn,
            isset($parts['user']) ? urldecode($parts['user']) : null,
            isset($parts['pass']) ? urldecode($parts['pass']) : null,
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadJson(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveJson(string $path, array $data): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
