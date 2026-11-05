<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Events;

/**
 * Dispatched after a successful Supabase Auth sign-up.
 */
final class UserSignedUp
{
    /**
     * @param  array<string, mixed>  $response  The raw GoTrue signup response (user + session).
     */
    public function __construct(public readonly array $response) {}
}
