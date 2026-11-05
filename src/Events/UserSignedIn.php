<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Events;

/**
 * Dispatched after a successful Supabase Auth sign-in (password grant).
 */
final class UserSignedIn
{
    /**
     * @param  array<string, mixed>  $session  The raw GoTrue token response (access/refresh token + user).
     */
    public function __construct(public readonly array $session) {}
}
