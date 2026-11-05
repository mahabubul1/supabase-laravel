<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Clients;

use BlackDevs\SupabaseLaravel\Exceptions\SupabaseException;
use BlackDevs\SupabaseLaravel\Exceptions\SupabasePostgrestException;
use BlackDevs\SupabaseLaravel\Postgrest\PostgrestQueryBuilder;
use BlackDevs\SupabaseLaravel\Realtime\RealtimeChannel;
use BlackDevs\SupabaseLaravel\SupabaseConnectionConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Base HTTP client for a single Supabase auth context (service-role, user, or anonymous).
 */
class SupabaseHttpClient
{
    /**
     * Claim/payload keys never written to logs, even in debug mode.
     *
     * @var array<int, string>
     */
    protected const REDACTED_KEYS = [
        'password', 'access_token', 'refresh_token', 'apikey', 'authorization',
    ];

    /**
     * HTTP statuses worth retrying: rate-limited or transiently unavailable.
     *
     * @var array<int, int>
     */
    protected const RETRYABLE_STATUSES = [429, 503];

    public function __construct(
        protected SupabaseConnectionConfig $config,
        protected string $apiKey,
        protected ?string $bearerToken,
        protected array $httpConfig,
        protected array $loggingConfig,
    ) {}

    protected function pendingRequest(string $requestId): PendingRequest
    {
        $request = Http::baseUrl($this->config->url)
            ->timeout($this->httpConfig['timeout'] ?? 10)
            ->retry(
                (int) ($this->httpConfig['retry']['times'] ?? 2),
                fn (int $attempt, \Throwable $exception) => $this->retryDelayMilliseconds($attempt, $exception),
                fn (\Throwable $exception) => $this->shouldRetry($exception),
                throw: false,
            )
            ->withHeaders([
                'apikey' => $this->apiKey,
                'Authorization' => 'Bearer '.($this->bearerToken ?? $this->apiKey),
            ]);

        if ($this->loggingEnabled()) {
            $request->beforeSending(function (Request $request) use ($requestId) {
                $this->logger()->debug('Supabase request', array_filter([
                    'request_id' => $requestId,
                    'method' => $request->method(),
                    'url' => (string) $request->url(),
                    'body' => $this->debugEnabled() ? $this->redact($request->data()) : null,
                ], fn ($value) => $value !== null));
            });
        }

        return $request;
    }

    public function get(string $uri, array $query = [], array $headers = []): Response
    {
        return $this->dispatch(fn (PendingRequest $request) => $request->get($uri, $query), $headers);
    }

    public function post(string $uri, array $data = [], array $headers = []): Response
    {
        return $this->dispatch(fn (PendingRequest $request) => $request->post($uri, $data), $headers);
    }

    public function put(string $uri, array $data = [], array $headers = []): Response
    {
        return $this->dispatch(fn (PendingRequest $request) => $request->put($uri, $data), $headers);
    }

    public function patch(string $uri, array $data = [], array $headers = []): Response
    {
        return $this->dispatch(fn (PendingRequest $request) => $request->patch($uri, $data), $headers);
    }

    public function delete(string $uri, array $data = [], array $headers = []): Response
    {
        return $this->dispatch(fn (PendingRequest $request) => $request->delete($uri, $data), $headers);
    }

    public function postRaw(string $uri, string $body, string $contentType, array $headers = []): Response
    {
        return $this->dispatch(
            fn (PendingRequest $request) => $request->withBody($body, $contentType)->post($uri),
            $headers,
        );
    }

    public function putRaw(string $uri, string $body, string $contentType, array $headers = []): Response
    {
        return $this->dispatch(
            fn (PendingRequest $request) => $request->withBody($body, $contentType)->put($uri),
            $headers,
        );
    }

    /**
     * Build the request, send it, and log a structured, redacted response entry.
     *
     * @param  \Closure(PendingRequest): Response  $send
     */
    protected function dispatch(\Closure $send, array $headers): Response
    {
        $requestId = (string) Str::ulid();
        $startedAt = microtime(true);

        $response = $send($this->pendingRequest($requestId)->withHeaders($headers));

        $this->logResponse($response, $requestId, $startedAt);

        return $response;
    }

    /**
     * Only retry connection failures and rate-limit/transient server errors —
     * never retry a client error like 400/404/422, which won't change on replay.
     */
    protected function shouldRetry(\Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            return true;
        }

        return in_array($exception->response->status(), self::RETRYABLE_STATUSES, true);
    }

    /**
     * Honor the server's Retry-After header on 429s (seconds or an HTTP
     * date); otherwise fall back to configured exponential-ish backoff.
     */
    protected function retryDelayMilliseconds(int $attempt, \Throwable $exception): int
    {
        $configuredMs = (int) ($this->httpConfig['retry']['sleep_ms'] ?? 200);

        if (! $exception instanceof RequestException) {
            return $configuredMs;
        }

        $retryAfter = $exception->response->header('Retry-After');

        if ($retryAfter === '' || $retryAfter === null) {
            return $configuredMs * $attempt;
        }

        if (is_numeric($retryAfter)) {
            return ((int) $retryAfter) * 1000;
        }

        $seconds = strtotime($retryAfter) - time();

        return max($seconds, 0) * 1000;
    }

    protected function logResponse(Response $response, string $requestId, float $startedAt): void
    {
        if (! $this->loggingEnabled()) {
            return;
        }

        $this->logger()->log($response->failed() ? 'warning' : 'debug', 'Supabase response', array_filter([
            'request_id' => $requestId,
            'status' => $response->status(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'body' => $this->debugEnabled() ? $this->redactedBody($response) : null,
        ], fn ($value) => $value !== null));
    }

    protected function redactedBody(Response $response): string
    {
        $json = $response->json();

        return $json !== null
            ? (string) json_encode($this->redact($json), JSON_UNESCAPED_UNICODE)
            : $this->truncate($response->body());
    }

    /**
     * Recursively mask any known-sensitive keys (passwords, tokens, api keys)
     * so debug logs never contain credentials.
     */
    protected function redact(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            $data[$key] = is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)
                ? '••••••••'
                : $this->redact($value);
        }

        return $data;
    }

    protected function truncate(string $value, int $limit = 2000): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit).'…(truncated)' : $value;
    }

    protected function loggingEnabled(): bool
    {
        return (bool) ($this->loggingConfig['enabled'] ?? false);
    }

    protected function debugEnabled(): bool
    {
        return (bool) ($this->loggingConfig['debug'] ?? false);
    }

    protected function logger(): LoggerInterface
    {
        return Log::channel($this->loggingConfig['channel'] ?? null);
    }

    /**
     * @throws SupabaseException
     */
    public function throwIfFailed(Response $response): Response
    {
        if ($response->failed()) {
            throw SupabaseException::fromResponse($response);
        }

        return $response;
    }

    /**
     * Start a fluent PostgREST query against a table or view.
     */
    public function from(string $table): PostgrestQueryBuilder
    {
        return new PostgrestQueryBuilder($this, $table);
    }

    /**
     * Get a handle for sending Realtime broadcast messages on the given topic.
     *
     * Receiving postgres_changes/broadcast/presence events requires a persistent
     * WebSocket connection and must be done client-side (see @supabase/supabase-js).
     */
    public function channel(string $topic): RealtimeChannel
    {
        return new RealtimeChannel($this, $topic);
    }

    /**
     * Call a Postgres function exposed via PostgREST (POST /rest/v1/rpc/{fn}).
     *
     * @param  array<string, mixed>  $params
     *
     * @throws SupabasePostgrestException
     */
    public function rpc(string $function, array $params = []): mixed
    {
        $response = $this->post("/rest/v1/rpc/{$function}", $params);

        if ($response->failed()) {
            throw SupabasePostgrestException::fromResponse($response);
        }

        return $response->json();
    }
}
