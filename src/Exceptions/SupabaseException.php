<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Exceptions;

use Exception;
use Illuminate\Http\Client\Response;

class SupabaseException extends Exception
{
    public function __construct(
        string $message,
        protected int $status = 0,
        protected ?array $body = null,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): static
    {
        $body = $response->json();

        $message = $body['message'] ?? $body['error_description'] ?? $body['msg'] ?? 'Supabase request failed.';

        return new static($message, $response->status(), $body);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): ?array
    {
        return $this->body;
    }
}
