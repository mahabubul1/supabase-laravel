<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Wraps the claims of a verified Supabase JWT as a Laravel Authenticatable.
 */
class SupabaseUser implements Authenticatable
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function __construct(protected array $claims) {}

    public function getAuthIdentifierName(): string
    {
        return 'sub';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->claims['sub'] ?? null;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // Supabase-issued JWTs are stateless; remember tokens are not supported.
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    public function claims(): array
    {
        return $this->claims;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->claims[$key] ?? $default;
    }

    public function __get(string $name): mixed
    {
        return $this->claims[$name] ?? null;
    }

    /**
     * The primary role claim, for use in Gate/Policy authorization checks.
     *
     * Reads Supabase's `app_metadata.role` convention first (set via Auth
     * Hooks/Admin API), falling back to the top-level `role` claim (the
     * Postgres role, e.g. "authenticated" — rarely useful for app-level
     * authorization on its own).
     */
    public function role(): ?string
    {
        return $this->claims['app_metadata']['role'] ?? $this->claims['role'] ?? null;
    }

    /**
     * All role claims for this user, for apps that assign more than one.
     *
     * @return array<int, string>
     */
    public function roles(): array
    {
        $roles = $this->claims['app_metadata']['roles'] ?? null;

        if (is_array($roles)) {
            return array_values(array_map('strval', $roles));
        }

        return array_values(array_filter([$this->role()]));
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles(), true);
    }

    /**
     * @param  array<int, string>  $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        return array_intersect($roles, $this->roles()) !== [];
    }

    /**
     * Read a multi-tenancy claim, checking `app_metadata` first (Supabase's
     * convention for claims set via a Custom Access Token Auth Hook), then
     * the claim's top level.
     */
    public function tenantId(string $claim = 'tenant_id'): mixed
    {
        return $this->claims['app_metadata'][$claim] ?? $this->claims[$claim] ?? null;
    }
}
