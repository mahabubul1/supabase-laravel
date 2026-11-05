<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Cache;

use BlackDevs\SupabaseLaravel\Postgrest\PostgrestQueryBuilder;
use BlackDevs\SupabaseLaravel\SupabaseManager;
use Illuminate\Contracts\Cache\Store;

/**
 * A Cache store backed by a Postgres table via PostgREST, for apps that keep
 * no local database/Redis and want cached values to live in Supabase too.
 *
 * Expects a table (default `cache`) with columns:
 *   key        text primary key
 *   value      text not null
 *   expiration bigint null  -- unix timestamp; null means "forever"
 *
 * Reads/writes use the service-role client so RLS never blocks the cache
 * layer itself — this table should not be exposed to anon/authenticated
 * roles in Postgres.
 *
 * Increment/decrement are read-then-write and therefore not atomic under
 * concurrent access; avoid relying on them for correctness-critical counters.
 */
class SupabaseStore implements Store
{
    public function __construct(
        protected SupabaseManager $manager,
        protected string $table = 'cache',
        protected ?string $connection = null,
        protected string $prefix = '',
    ) {}

    public function get($key): mixed
    {
        $row = $this->query()->eq('key', $this->prefixed($key))->maybeSingle();

        if ($row === null || $this->expired($row)) {
            return null;
        }

        return unserialize($row['value']);
    }

    public function many(array $keys): array
    {
        return array_combine($keys, array_map($this->get(...), $keys));
    }

    public function put($key, $value, $seconds): bool
    {
        $this->query()->upsert([
            'key' => $this->prefixed($key),
            'value' => serialize($value),
            'expiration' => $seconds > 0 ? now()->addSeconds($seconds)->getTimestamp() : null,
        ], onConflict: ['key'])->get();

        return true;
    }

    public function putMany(array $values, $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1): int|false
    {
        $new = ((int) $this->get($key)) + $value;

        return $this->forever($key, $new) ? $new : false;
    }

    public function decrement($key, $value = 1): int|false
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function forget($key): bool
    {
        $this->query()->eq('key', $this->prefixed($key))->delete()->get();

        return true;
    }

    public function flush(): bool
    {
        $this->query()->like('key', $this->prefix.'%')->delete()->get();

        return true;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    protected function query(): PostgrestQueryBuilder
    {
        return $this->manager->table($this->table, $this->connection);
    }

    protected function prefixed(string $key): string
    {
        return $this->prefix.$key;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function expired(array $row): bool
    {
        return $row['expiration'] !== null && (int) $row['expiration'] < now()->getTimestamp();
    }
}
