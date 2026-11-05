<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Events;

/**
 * Dispatched after a Supabase Auth sign-out. Only the access token that was
 * revoked is included — never log or broadcast this event's payload.
 */
final class UserSignedOut
{
    public function __construct(public readonly string $accessToken) {}
}
