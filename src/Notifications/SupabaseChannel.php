<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Notifications;

use BlackDevs\SupabaseLaravel\SupabaseManager;
use Illuminate\Notifications\Notification;
use RuntimeException;

/**
 * Delivers notifications as a Supabase Realtime broadcast message, so a
 * connected browser client (see resources/js/lib/supabase-realtime.ts)
 * receives it live via `onBroadcast('notification', ...)`.
 *
 * Usage: return `[SupabaseChannel::class]` from a Notification's `via()`
 * method and define `toSupabase($notifiable): array` on it.
 */
class SupabaseChannel
{
    public function __construct(protected SupabaseManager $manager) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSupabase')) {
            throw new RuntimeException(sprintf(
                '%s must define a toSupabase() method to be sent via the Supabase notification channel.',
                $notification::class,
            ));
        }

        $payload = $notification->toSupabase($notifiable);
        $payload = is_array($payload) ? $payload : (array) $payload;

        $topic = method_exists($notifiable, 'receivesSupabaseBroadcastsOn')
            ? $notifiable->receivesSupabaseBroadcastsOn($notification)
            : $this->defaultTopic($notifiable);

        $this->manager->channel($topic)->broadcast('notification', array_merge(
            ['type' => $notification::class],
            $payload,
        ), private: true);
    }

    protected function defaultTopic(mixed $notifiable): string
    {
        $key = method_exists($notifiable, 'getKey') ? $notifiable->getKey() : (string) $notifiable;

        return "notifications.{$key}";
    }
}
