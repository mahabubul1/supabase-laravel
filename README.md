# Supabase Laravel

A Laravel package for talking to a [Supabase](https://supabase.com) project's REST API (PostgREST), with support for service-role, user-authenticated, and anonymous request contexts.

## Features

- **Multiple auth contexts** — `serviceRole()` (bypasses RLS), `asUser($token)` (acts as a specific user, respects RLS), `anonymous()` (anon key, respects RLS).
- **Multiple named connections** — configure more than one Supabase project/environment in `config/supabase.php`.
- **Fluent PostgREST query builder** (`Supabase::table(...)`):
  - `select()` with column lists
  - `insert()`, `update()`, `upsert()` (with `onConflict`), `delete()`
  - `where()` raw escape hatch plus typed filters: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `is`, `like`, `ilike`, `contains`
  - `orWhere()` for OR-grouped conditions
  - `orderBy()`, `limit()`, `offset()`
  - `get()` → `Collection`, `single()` → array (throws unless exactly one row), `maybeSingle()` → array|null
  - `paginate()` using `Prefer: count=exact` + `Content-Range`, returning data + page metadata
- **RPC support** — call Postgres functions exposed via PostgREST with `Supabase::rpc()`.
- **Realtime**:
  - `Supabase::channel($topic)->broadcast($event, $payload)` — send broadcast messages from PHP over plain HTTP
  - A `supabase` Laravel Broadcasting driver, so `event(new SomethingHappened)` / `ShouldBroadcast` work unmodified, reusing `routes/channels.php` authorization
  - `resources/js/lib/supabase-realtime.ts` — browser-side subscribe/unsubscribe lifecycle, Postgres change events (insert/update/delete filters), broadcast receive, and presence (`track`/`untrack`/`sync`/`join`/`leave`), via `@supabase/supabase-js`
- **Laravel integration**:
  - **Events** — `UserSignedUp`, `UserSignedIn`, `UserSignedOut`, `FileUploaded`, `FileDeleted`
  - **Gates/Policies** — `SupabaseUser::role()`/`roles()`/`hasRole()`/`hasAnyRole()` read straight from verified JWT claims
  - **Queue** — `CallSupabase::dispatch(fn () => ...)`, a `ShouldQueue` wrapper for any Supabase call
  - **Notifications** — `SupabaseChannel` delivers notifications as a Realtime broadcast
  - **Cache** — `Cache::store('supabase')`, a `Store` implementation backed by a Postgres table via PostgREST
  - **Validation** — `SupabaseUnique`, a `Rule::unique()` equivalent that checks a Supabase table
- **Typed exceptions** — `SupabaseException` / `SupabasePostgrestException` / `SupabaseRealtimeException` expose `->status()` and `->body()` from the failed response.
- **Built-in HTTP resilience** — configurable timeout and retry (`config('supabase.http')`).
- **Developer experience**:
  - `php artisan supabase:install` — interactive `.env` + config setup
  - `php artisan supabase:test` — PostgREST/Auth/Storage connectivity health check, CI-friendly exit code
  - `php artisan supabase:make-function {name}` — scaffold a `.sql` file for a Postgres RPC function
  - `php artisan supabase:push-functions` — push changed `.sql` function files directly to Postgres via `db_url`, skipping unchanged ones
  - `php artisan supabase:rollback-function {function}` — restore a function's previously pushed version
  - `php artisan supabase:make-table {name}` — scaffold a timestamped `.sql` migration file (Laravel migration naming), with `-- up`/`-- down` sections
  - `php artisan supabase:migrate {table?}` — run pending table migrations in order, tracked by filename and batch (like `php artisan migrate`)
  - `php artisan supabase:rollback-table {name?}` — reverse the last migration batch, or a single named migration, using its `-- down` section (like `php artisan migrate:rollback`)
  - `php artisan supabase:make-seeder {name}` — scaffold a PHP class seeder (via the `Supabase` facade/PostgREST); `--sql` scaffolds a raw-SQL seeder via `db_url` instead
  - `php artisan supabase:seed {name?}` — run seeders (like `php artisan db:seed`); re-runnable, not tracked as "applied"
  - Structured, correlated (`request_id` + `duration_ms`) request/response logging with automatic redaction of passwords/tokens/api keys, gated behind `SUPABASE_LOG_ENABLED`/`SUPABASE_DEBUG`
  - Config publishing via `php artisan vendor:publish --tag=supabase-config`
- **Self-contained test suite** (Orchestra Testbench + Pest) shipped with the package.

## Documentation

- [Installation & Configuration](docs/installation.md) — setup, `.env`, auth contexts
- [Query Builder](docs/query-builder.md) — select/insert/update/upsert/delete, pagination, single-row helpers
- [Filters](docs/filters.md) — `eq`, `in`, `like`, `contains`, `orWhere`, etc.
- [RPC & Errors](docs/rpc-and-errors.md) — calling Postgres functions, exception handling
- [Storage](docs/storage.md) — upload/download/delete/move/copy/list, public/signed URLs, bucket management
- [Realtime](docs/realtime.md) — channels, broadcast, Postgres change events, presence, subscribe/unsubscribe, the Laravel Broadcasting bridge
- [Laravel Integration](docs/laravel-integration.md) — events, Gates/Policies, queue, notifications, cache, validation
- [Developer Experience](docs/developer-experience.md) — artisan commands, config publishing, debug mode, structured logging
- [Testing](docs/testing.md) — running and writing tests for this package

## Quick example

```php
use BlackDevs\SupabaseLaravel\Facades\Supabase;

$users = Supabase::table('users')
    ->select('id,name,email')
    ->eq('active', true)
    ->gte('age', 18)
    ->orderBy('name')
    ->limit(10)
    ->get();
```

See [docs/installation.md](docs/installation.md) to get started.
