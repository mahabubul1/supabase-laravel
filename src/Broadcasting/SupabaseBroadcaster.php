<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Broadcasting;

use BlackDevs\SupabaseLaravel\Clients\SupabaseHttpClient;
use BlackDevs\SupabaseLaravel\Exceptions\SupabaseRealtimeException;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Broadcasting\BroadcastException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Broadcasts Laravel events to Supabase Realtime as broadcast messages, so
 * frontend clients subscribed via @supabase/supabase-js receive them.
 *
 * Channel names follow Laravel's usual `private-`/`presence-` conventions for
 * authorizing *who may subscribe according to your app* (via `Broadcast::channel()`
 * in routes/channels.php). This is a Laravel-side authorization layer only —
 * it does not by itself enforce who can read from Supabase's socket; pair it
 * with Row Level Security policies on the `realtime.messages` table for that.
 */
class SupabaseBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    public function __construct(protected SupabaseHttpClient $client) {}

    public function auth($request)
    {
        $channelName = $this->normalizeChannelName($request->channel_name);

        if (empty($request->channel_name) ||
            ($this->isGuardedChannel($request->channel_name) &&
            ! $this->retrieveUser($request, $channelName))) {
            throw new AccessDeniedHttpException;
        }

        return parent::verifyUserCanAccessChannel($request, $channelName);
    }

    public function validAuthenticationResponse($request, $result)
    {
        if (str_starts_with((string) $request->channel_name, 'presence-')) {
            $user = $this->retrieveUser($request, $this->normalizeChannelName($request->channel_name));

            return [
                'channel' => $request->channel_name,
                'user' => is_array($result) ? $result : [
                    'id' => $user?->getAuthIdentifier(),
                ],
            ];
        }

        return ['channel' => $request->channel_name];
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        try {
            foreach ($this->formatChannels($channels) as $channel) {
                $this->client->channel($this->normalizeChannelName($channel))
                    ->broadcast($event, $payload, private: $this->isGuardedChannel($channel));
            }
        } catch (SupabaseRealtimeException $e) {
            throw new BroadcastException($e->getMessage());
        }
    }
}
