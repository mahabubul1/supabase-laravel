<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Events;

/**
 * Dispatched after a successful Supabase Storage object deletion.
 */
final class FileDeleted
{
    /**
     * @param  array<int, string>  $paths
     */
    public function __construct(
        public readonly string $bucket,
        public readonly array $paths,
    ) {}
}
