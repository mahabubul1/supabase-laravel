<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel;

use BlackDevs\SupabaseLaravel\Auth\SupabaseAuthClient;
use BlackDevs\SupabaseLaravel\Clients\SupabaseHttpClient;
use BlackDevs\SupabaseLaravel\Functions\SupabaseFunctionsClient;
use BlackDevs\SupabaseLaravel\Postgrest\PostgrestQueryBuilder;
use BlackDevs\SupabaseLaravel\Realtime\RealtimeChannel;
use BlackDevs\SupabaseLaravel\Storage\SupabaseStorageClient;
use BlackDevs\SupabaseLaravel\Vector\SupabaseVectorClient;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Resolves Supabase HTTP clients per connection and auth context
 * (service-role, user-authenticated, or anonymous).
 */
class SupabaseManager
{
    /** @var array<string, SupabaseConnectionConfig> */
    protected array $configs = [];

    public function __construct(protected array $config) {}

    public function connection(?string $name = null): SupabaseConnectionConfig
    {
        $name ??= $this->config['default'];

        return $this->configs[$name] ??= SupabaseConnectionConfig::fromArray(
            $this->config['connections'][$name]
                ?? throw new InvalidArgumentException("Supabase connection [{$name}] is not configured.")
        );
    }

    public function serviceRole(?string $connection = null): SupabaseHttpClient
    {
        $config = $this->connection($connection);

        return new SupabaseHttpClient(
            config: $config,
            apiKey: $config->serviceRoleKey ?? throw new InvalidArgumentException('Supabase service_role_key is not set.'),
            bearerToken: $config->serviceRoleKey,
            httpConfig: $this->config['http'] ?? [],
            loggingConfig: $this->config['logging'] ?? [],
        );
    }

    public function asUser(string $accessToken, ?string $connection = null): SupabaseHttpClient
    {
        $config = $this->connection($connection);

        return new SupabaseHttpClient(
            config: $config,
            apiKey: $config->anonKey ?? throw new InvalidArgumentException('Supabase anon_key is not set.'),
            bearerToken: $accessToken,
            httpConfig: $this->config['http'] ?? [],
            loggingConfig: $this->config['logging'] ?? [],
        );
    }

    public function anonymous(?string $connection = null): SupabaseHttpClient
    {
        $config = $this->connection($connection);

        return new SupabaseHttpClient(
            config: $config,
            apiKey: $config->anonKey ?? throw new InvalidArgumentException('Supabase anon_key is not set.'),
            bearerToken: null,
            httpConfig: $this->config['http'] ?? [],
            loggingConfig: $this->config['logging'] ?? [],
        );
    }

    /**
     * Start a fluent PostgREST query using the service-role client.
     */
    public function table(string $table, ?string $connection = null): PostgrestQueryBuilder
    {
        return $this->serviceRole($connection)->from($table);
    }

    /**
     * Call a Postgres function via the service-role client.
     *
     * @param  array<string, mixed>  $params
     */
    public function rpc(string $function, array $params = [], ?string $connection = null): mixed
    {
        return $this->serviceRole($connection)->rpc($function, $params);
    }

    /**
     * Get the Auth (GoTrue) API client. Pass a user's access token to act on
     * their behalf (getUser, updateUser, signOut); omit it for anonymous
     * operations (signUp, signIn, resetPasswordForEmail, etc.).
     */
    public function auth(?string $accessToken = null, ?string $connection = null): SupabaseAuthClient
    {
        $client = $accessToken
            ? $this->asUser($accessToken, $connection)
            : $this->anonymous($connection);

        return new SupabaseAuthClient($client, $this->serviceRole($connection), $this->connection($connection));
    }

    /**
     * Get a handle for sending Realtime broadcast messages on the given topic,
     * using the service-role client. Receiving events requires a persistent
     * WebSocket connection and must be done client-side.
     */
    public function channel(string $topic, ?string $connection = null): RealtimeChannel
    {
        return $this->serviceRole($connection)->channel($topic);
    }

    /**
     * Get the Storage API client. Pass a user's access token to operate under
     * their RLS policies; omit it to use the service-role client.
     */
    public function storage(?string $accessToken = null, ?string $connection = null): SupabaseStorageClient
    {
        $client = $accessToken
            ? $this->asUser($accessToken, $connection)
            : $this->serviceRole($connection);

        return new SupabaseStorageClient($client, $this->connection($connection));
    }

    /**
     * Get the Edge Functions client. Pass a user's access token to invoke
     * functions on their behalf; omit it for anonymous invocations.
     */
    public function functions(?string $accessToken = null, ?string $connection = null): SupabaseFunctionsClient
    {
        $client = $accessToken
            ? $this->asUser($accessToken, $connection)
            : $this->anonymous($connection);

        return new SupabaseFunctionsClient($client);
    }

    /**
     * Invoke an Edge Function using the anonymous client (or a user's access
     * token, if provided).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function invoke(string $functionName, array $payload = [], array $headers = [], ?string $accessToken = null, ?string $connection = null): mixed
    {
        return $this->functions($accessToken, $connection)->invoke($functionName, $payload, $headers);
    }

    /**
     * Fake HTTP responses for Supabase calls in tests. Keys may be a relative
     * path/pattern (e.g. 'rest/v1/orders*'), which is resolved against the
     * connection's base URL, or an already-absolute URL/pattern.
     *
     * @param  array<string, mixed>  $responses
     */
    public function fake(array $responses = [], ?string $connection = null): void
    {
        $baseUrl = rtrim($this->connection($connection)->url, '/');

        $prefixed = [];

        foreach ($responses as $pattern => $response) {
            $prefixed[str_starts_with($pattern, 'http') ? $pattern : "{$baseUrl}/".ltrim($pattern, '/')] = $response;
        }

        Http::fake($prefixed);
    }

    /**
     * Get the pgvector embedding storage/query helper, using the service-role client.
     */
    public function vector(?string $connection = null): SupabaseVectorClient
    {
        return new SupabaseVectorClient($this->serviceRole($connection));
    }
}
