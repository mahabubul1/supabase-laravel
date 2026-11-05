<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Events;

/**
 * Dispatched after a successful Supabase Storage object upload.
 */
final class FileUploaded
{
    /**
     * @param  array<string, mixed>  $response  The raw Storage API response.
     */
    public function __construct(
        public readonly string $bucket,
        public readonly string $path,
        public readonly array $response,
    ) {}
}
