<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Auth;

use BlackDevs\SupabaseLaravel\Clients\SupabaseHttpClient;
use BlackDevs\SupabaseLaravel\Events\UserSignedIn;
use BlackDevs\SupabaseLaravel\Events\UserSignedOut;
use BlackDevs\SupabaseLaravel\Events\UserSignedUp;
use BlackDevs\SupabaseLaravel\Exceptions\SupabaseAuthException;
use BlackDevs\SupabaseLaravel\SupabaseConnectionConfig;

/**
 * Wraps the Supabase Auth (GoTrue) HTTP API: sign up/in/out, token refresh,
 * user management, password reset, OAuth, magic links, and OTP verification.
 */
class SupabaseAuthClient
{
    public function __construct(
        protected SupabaseHttpClient $client,
        protected SupabaseHttpClient $adminClient,
        protected SupabaseConnectionConfig $config,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Additional user_metadata to store on sign up.
     */
    public function signUp(string $email, string $password, array $data = []): array
    {
        $response = $this->send('post', '/auth/v1/signup', [
            'email' => $email,
            'password' => $password,
            'data' => $data,
        ]);

        event(new UserSignedUp($response));

        return $response;
    }

    public function signInWithPassword(string $email, string $password): array
    {
        $session = $this->send('post', '/auth/v1/token?grant_type=password', [
            'email' => $email,
            'password' => $password,
        ]);

        event(new UserSignedIn($session));

        return $session;
    }

    /**
     * Send a magic link (passwordless sign-in) email.
     */
    public function signInWithMagicLink(string $email, ?string $redirectTo = null): bool
    {
        $this->send('post', '/auth/v1/otp', array_filter([
            'email' => $email,
            'create_user' => true,
        ], fn ($value) => $value !== null), $redirectTo);

        return true;
    }

    /**
     * Verify an OTP/magic-link/email-confirmation token.
     *
     * @param  string  $type  One of: signup, magiclink, recovery, invite, email_change.
     */
    public function verifyOtp(string $email, string $token, string $type = 'magiclink'): array
    {
        return $this->send('post', '/auth/v1/verify', [
            'email' => $email,
            'token' => $token,
            'type' => $type,
        ]);
    }

    /**
     * Build the redirect URL to start an OAuth sign-in flow.
     */
    public function oauthAuthorizeUrl(string $provider, ?string $redirectTo = null): string
    {
        $query = array_filter([
            'provider' => $provider,
            'redirect_to' => $redirectTo,
        ], fn ($value) => $value !== null);

        return rtrim($this->config->url, '/').'/auth/v1/authorize?'.http_build_query($query);
    }

    public function signOut(string $accessToken): bool
    {
        $this->send('post', '/auth/v1/logout', [], null, $accessToken);

        event(new UserSignedOut($accessToken));

        return true;
    }

    public function getUser(string $accessToken): array
    {
        return $this->send('get', '/auth/v1/user', accessToken: $accessToken);
    }

    /**
     * @param  array<string, mixed>  $attributes  e.g. ['email' => ..., 'password' => ..., 'data' => [...]]
     */
    public function updateUser(string $accessToken, array $attributes): array
    {
        return $this->send('put', '/auth/v1/user', $attributes, accessToken: $accessToken);
    }

    public function refreshToken(string $refreshToken): array
    {
        return $this->send('post', '/auth/v1/token?grant_type=refresh_token', [
            'refresh_token' => $refreshToken,
        ]);
    }

    public function resetPasswordForEmail(string $email, ?string $redirectTo = null): bool
    {
        $this->send('post', '/auth/v1/recover', ['email' => $email], $redirectTo);

        return true;
    }

    /**
     * Permanently delete a user by ID. Requires the service-role key.
     */
    public function deleteUser(string $userId): bool
    {
        $response = $this->adminClient->delete("/auth/v1/admin/users/{$userId}");

        if ($response->failed()) {
            throw SupabaseAuthException::fromResponse($response);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(string $method, string $uri, array $payload = [], ?string $redirectTo = null, ?string $accessToken = null): array
    {
        $uri = $redirectTo ? $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query(['redirect_to' => $redirectTo]) : $uri;

        $headers = $accessToken ? ['Authorization' => "Bearer {$accessToken}"] : [];

        $response = match ($method) {
            'post' => $this->client->post($uri, $payload, $headers),
            'put' => $this->client->put($uri, $payload, $headers),
            'get' => $this->client->get($uri, [], $headers),
            default => throw new SupabaseAuthException("Unsupported HTTP method [{$method}]."),
        };

        if ($response->failed()) {
            throw SupabaseAuthException::fromResponse($response);
        }

        return $response->json() ?? [];
    }
}
