<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Auth;

use BlackDevs\SupabaseLaravel\Exceptions\SupabaseAuthException;

/**
 * Verifies and decodes Supabase-issued HS256 JWTs without external dependencies.
 */
class SupabaseJwt
{
    /**
     * @return array<string, mixed>
     *
     * @throws SupabaseAuthException
     */
    public static function decode(string $jwt, string $secret): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new SupabaseAuthException('Malformed JWT.');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode(self::base64UrlDecode($headerB64), true);

        if (($header['alg'] ?? null) !== 'HS256') {
            throw new SupabaseAuthException('Unsupported JWT algorithm.');
        }

        $expectedSignature = self::base64UrlEncode(
            hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true)
        );

        if (! hash_equals($expectedSignature, $signatureB64)) {
            throw new SupabaseAuthException('Invalid JWT signature.');
        }

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);

        if (! is_array($payload)) {
            throw new SupabaseAuthException('Invalid JWT payload.');
        }

        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new SupabaseAuthException('JWT has expired.');
        }

        return $payload;
    }

    protected static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;

        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
