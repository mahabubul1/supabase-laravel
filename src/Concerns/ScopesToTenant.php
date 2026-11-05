<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Concerns;

use BlackDevs\SupabaseLaravel\Auth\SupabaseUser;
use BlackDevs\SupabaseLaravel\Facades\Supabase;
use BlackDevs\SupabaseLaravel\Postgrest\PostgrestQueryBuilder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Auto-injects the authenticated user's tenant id (a custom JWT claim) into
 * PostgREST queries. Requires config('supabase.tenancy.enabled') and a
 * Supabase Custom Access Token Auth Hook that adds the configured claim —
 * see the package README for setup.
 */
trait ScopesToTenant
{
    /**
     * The current request's tenant id, read from the authenticated user's
     * JWT claim configured as `supabase.tenancy.claim`.
     *
     * @throws RuntimeException if tenancy is disabled, the user is
     *                          unauthenticated, or the claim is missing.
     */
    protected function currentTenantId(?string $guard = 'supabase'): mixed
    {
        if (! config('supabase.tenancy.enabled')) {
            throw new RuntimeException('Multi-tenancy is disabled. Set SUPABASE_TENANCY_ENABLED=true to use tenant scoping.');
        }

        $user = Auth::guard($guard)->user();

        if (! $user instanceof SupabaseUser) {
            throw new RuntimeException('No authenticated Supabase user to resolve a tenant id from.');
        }

        $tenantId = $user->tenantId(config('supabase.tenancy.claim'));

        if ($tenantId === null) {
            throw new RuntimeException(sprintf(
                'JWT is missing the "%s" tenant claim. Confirm the Custom Access Token Auth Hook is configured in Supabase.',
                config('supabase.tenancy.claim'),
            ));
        }

        return $tenantId;
    }

    /**
     * Start a PostgREST query against $table, pre-filtered to the current
     * tenant on the configured tenant column.
     */
    protected function tenantTable(string $table, ?string $connection = null, ?string $guard = 'supabase'): PostgrestQueryBuilder
    {
        return $this->scopeToTenant(Supabase::table($table, $connection), guard: $guard);
    }

    /**
     * Apply the current tenant filter to an existing query builder instance.
     */
    protected function scopeToTenant(PostgrestQueryBuilder $query, ?string $column = null, ?string $guard = 'supabase'): PostgrestQueryBuilder
    {
        return $query->eq($column ?? config('supabase.tenancy.column'), $this->currentTenantId($guard));
    }
}
