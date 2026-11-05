<?php

return [
    'default' => env('SUPABASE_CONNECTION', 'main'),

    'connections' => [
        'main' => [
            'url' => env('SUPABASE_URL'),
            'anon_key' => env('SUPABASE_ANON_KEY'),
            'service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
            'jwt_secret' => env('SUPABASE_JWT_SECRET'),

            // Direct Postgres connection string (Session or Transaction pooler
            // URI from Project Settings > Database). Only needed for
            // `supabase:push-functions` — PostgREST cannot run DDL, so pushing
            // function definitions requires a real Postgres connection.
            'db_url' => env('SUPABASE_DB_URL'),
        ],
    ],

    'http' => [
        'timeout' => env('SUPABASE_HTTP_TIMEOUT', 10),
        'retry' => [
            'times' => env('SUPABASE_HTTP_RETRY_TIMES', 2),
            'sleep_ms' => env('SUPABASE_HTTP_RETRY_SLEEP_MS', 200),
        ],
    ],

    'logging' => [
        'enabled' => env('SUPABASE_LOG_ENABLED', false),
        'channel' => env('SUPABASE_LOG_CHANNEL', config('logging.default')),

        // Also logs request parameters and response bodies (with known sensitive
        // fields redacted). Verbose — use for local debugging, not production.
        'debug' => env('SUPABASE_DEBUG', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy
    |--------------------------------------------------------------------------
    |
    | When enabled, ScopesToTenant-using classes will automatically filter
    | PostgREST queries by the tenant id found in the authenticated user's
    | JWT. The tenant id must be present in the JWT as a custom claim —
    | Supabase does not add one by default. See the "claim" option below
    | and packages/blackdevs/supabase-laravel/README.md for how to add it
    | via a Custom Access Token Auth Hook.
    |
    */

    'tenancy' => [
        'enabled' => env('SUPABASE_TENANCY_ENABLED', false),

        // The JWT claim holding the tenant id. Checked under app_metadata
        // first (Supabase's convention for auth-hook-set claims), then at
        // the claim's top level.
        'claim' => env('SUPABASE_TENANT_CLAIM', 'tenant_id'),

        // The Postgres/PostgREST column each tenant-scoped table filters on.
        'column' => env('SUPABASE_TENANT_COLUMN', 'tenant_id'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Webhooks (inbound)
    |--------------------------------------------------------------------------
    |
    | Supabase Database Webhooks don't sign requests (no HMAC like Stripe).
    | The supported pattern is a shared secret sent as a custom header — set
    | it under the webhook's "HTTP Headers" in the Supabase dashboard, then
    | protect your receiving route with the 'supabase.webhook' middleware.
    |
    */

    'webhooks' => [
        'secret' => env('SUPABASE_WEBHOOK_SECRET'),
        'header' => env('SUPABASE_WEBHOOK_HEADER', 'X-Supabase-Signature'),
    ],
];
