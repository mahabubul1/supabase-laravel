<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Functions;

use BlackDevs\SupabaseLaravel\Clients\SupabaseHttpClient;
use BlackDevs\SupabaseLaravel\Exceptions\SupabaseFunctionsException;

/**
 * Invokes Supabase Edge Functions (POST /functions/v1/{name}).
 */
class SupabaseFunctionsClient
{
    public function __construct(
        protected SupabaseHttpClient $client,
    ) {}

    /**
     * Invoke an Edge Function with a JSON payload.
     *
     * The function runs under whichever auth context this client was built
     * with (anonymous, a user's access token, or the service role) — pass
     * extra $headers to override or add to that per call.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     *
     * @throws SupabaseFunctionsException
     */
    public function invoke(string $functionName, array $payload = [], array $headers = []): mixed
    {
        $response = $this->client->post("/functions/v1/{$functionName}", $payload, $headers);

        if ($response->failed()) {
            throw SupabaseFunctionsException::fromResponse($response);
        }

        $json = $response->json();

        return $json ?? $response->body();
    }
}
