<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Console;

use BlackDevs\SupabaseLaravel\SupabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks connectivity to the configured Supabase project's PostgREST, Auth,
 * and Storage endpoints — a quick health check for deploys and debugging.
 */
class TestCommand extends Command
{
    protected $signature = 'supabase:test
        {--connection= : The config/supabase.php connection to test (defaults to the default connection)}';

    protected $description = "Check connectivity to the configured Supabase project's APIs";

    public function handle(SupabaseManager $manager): int
    {
        $config = $manager->connection($this->option('connection'));

        if (! $config->url) {
            $this->components->error('No Supabase URL configured. Run `php artisan supabase:install` first.');

            return self::FAILURE;
        }

        $checks = [
            'PostgREST' => rtrim($config->url, '/').'/rest/v1/',
            'Auth' => rtrim($config->url, '/').'/auth/v1/health',
            'Storage' => rtrim($config->url, '/').'/storage/v1/',
        ];

        $rows = [];
        $allOk = true;

        foreach ($checks as $label => $url) {
            $result = $this->ping($url, $config->anonKey);
            $allOk = $allOk && $result['ok'];

            $rows[] = [
                $label,
                $result['ok'] ? '<fg=green>OK</>' : '<fg=red>FAIL</>',
                $result['status'] ?? '—',
                $result['ms'] !== null ? "{$result['ms']} ms" : '—',
                $result['error'] ?? '',
            ];
        }

        $this->table(['Check', 'Status', 'HTTP', 'Latency', 'Error'], $rows);

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{ok: bool, status: ?int, ms: ?int, error: ?string}
     */
    protected function ping(string $url, ?string $apiKey): array
    {
        $startedAt = microtime(true);

        try {
            $response = Http::timeout(5)
                ->withHeaders(array_filter([
                    'apikey' => $apiKey,
                    'Authorization' => $apiKey ? "Bearer {$apiKey}" : null,
                ]))
                ->get($url);

            return [
                'ok' => $response->status() < 500,
                'status' => $response->status(),
                'ms' => $this->elapsedMs($startedAt),
                'error' => $response->status() >= 500 ? $this->summarize($response->body()) : null,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'status' => null,
                'ms' => $this->elapsedMs($startedAt),
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    protected function summarize(string $body): string
    {
        return mb_strlen($body) > 120 ? mb_substr($body, 0, 120).'…' : $body;
    }
}
