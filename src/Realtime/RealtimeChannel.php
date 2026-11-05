<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Realtime;

use BlackDevs\SupabaseLaravel\Clients\SupabaseHttpClient;
use BlackDevs\SupabaseLaravel\Exceptions\SupabaseRealtimeException;
use Illuminate\Http\Client\Response;

/**
 * Server-side handle for a single Supabase Realtime topic.
 *
 * Only sending broadcast messages is possible over plain HTTP. Actually
 * subscribing to postgres_changes/broadcast/presence events requires a
 * persistent WebSocket connection and must be done from the client
 * (see @supabase/supabase-js), or the `supabase` Laravel Broadcasting
 * driver for outbound events triggered from PHP.
 */
class RealtimeChannel
{
    public function __construct(
        protected SupabaseHttpClient $client,
        protected string $topic,
    ) {}

    /**
     * Send a broadcast message to every current subscriber of this topic.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws SupabaseRealtimeException
     */
    public function broadcast(string $event, array $payload = [], bool $private = false): Response
    {
        $response = $this->client->post('/realtime/v1/api/broadcast', [
            'messages' => [[
                'topic' => $this->topic,
                'event' => $event,
                'payload' => $payload,
                'private' => $private,
            ]],
        ]);

        if ($response->failed()) {
            throw SupabaseRealtimeException::fromResponse($response);
        }

        return $response;
    }
}
