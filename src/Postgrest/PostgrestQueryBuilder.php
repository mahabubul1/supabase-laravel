<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Postgrest;

use BlackDevs\SupabaseLaravel\Clients\SupabaseHttpClient;
use BlackDevs\SupabaseLaravel\Exceptions\SupabasePostgrestException;
use BlackDevs\SupabaseLaravel\Support\SupabaseResource;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;

/**
 * Fluent query builder for a single Supabase/PostgREST table or view.
 */
class PostgrestQueryBuilder
{
    protected string $select = '*';

    protected array $filters = [];

    protected array $orFilters = [];

    protected array $order = [];

    protected ?int $limitValue = null;

    protected ?int $offsetValue = null;

    protected ?string $method = null;

    protected mixed $payload = null;

    protected array $extraHeaders = [];

    public function __construct(
        protected SupabaseHttpClient $client,
        protected string $table,
    ) {}

    public function select(string $columns = '*'): static
    {
        $this->select = $columns;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function insert(array $values): static
    {
        $this->method = 'POST';
        $this->payload = $values;
        $this->extraHeaders['Prefer'] = 'return=representation';

        return $this;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): static
    {
        $this->method = 'PATCH';
        $this->payload = $values;
        $this->extraHeaders['Prefer'] = 'return=representation';

        return $this;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $onConflict
     */
    public function upsert(array $values, array $onConflict = []): static
    {
        $this->method = 'POST';
        $this->payload = $values;
        $this->extraHeaders['Prefer'] = 'resolution=merge-duplicates,return=representation';

        if ($onConflict !== []) {
            $this->filters['on_conflict'] = implode(',', $onConflict);
        }

        return $this;
    }

    public function delete(): static
    {
        $this->method = 'DELETE';

        return $this;
    }

    public function where(string $column, string $operator, mixed $value): static
    {
        $this->filters[$column] = "{$operator}.".$this->formatValue($value);

        return $this;
    }

    public function orWhere(array $conditions): static
    {
        $parts = [];

        foreach ($conditions as $column => [$operator, $value]) {
            $parts[] = "{$column}.{$operator}.".$this->formatValue($value);
        }

        $this->orFilters[] = implode(',', $parts);

        return $this;
    }

    public function eq(string $column, mixed $value): static
    {
        return $this->where($column, 'eq', $value);
    }

    public function neq(string $column, mixed $value): static
    {
        return $this->where($column, 'neq', $value);
    }

    public function gt(string $column, mixed $value): static
    {
        return $this->where($column, 'gt', $value);
    }

    public function gte(string $column, mixed $value): static
    {
        return $this->where($column, 'gte', $value);
    }

    public function lt(string $column, mixed $value): static
    {
        return $this->where($column, 'lt', $value);
    }

    public function lte(string $column, mixed $value): static
    {
        return $this->where($column, 'lte', $value);
    }

    public function in(string $column, array $values): static
    {
        $formatted = implode(',', array_map(fn ($value) => $this->formatValue($value), $values));

        $this->filters[$column] = "in.({$formatted})";

        return $this;
    }

    public function is(string $column, mixed $value): static
    {
        $value = match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            default => (string) $value,
        };

        $this->filters[$column] = "is.{$value}";

        return $this;
    }

    public function like(string $column, string $pattern): static
    {
        return $this->where($column, 'like', $pattern);
    }

    public function ilike(string $column, string $pattern): static
    {
        return $this->where($column, 'ilike', $pattern);
    }

    public function contains(string $column, mixed $value): static
    {
        $value = is_array($value) ? '{'.implode(',', $value).'}' : $value;

        $this->filters[$column] = 'cs.'.$this->formatValue($value);

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc', bool $nullsFirst = false): static
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        $this->order[] = "{$column}.{$direction}.".($nullsFirst ? 'nullsfirst' : 'nullslast');

        return $this;
    }

    public function limit(int $value): static
    {
        $this->limitValue = $value;

        return $this;
    }

    public function offset(int $value): static
    {
        $this->offsetValue = $value;

        return $this;
    }

    /**
     * Paginate results using limit/offset, returning items plus paging metadata.
     */
    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $this->limit($perPage)->offset(($page - 1) * $perPage);
        $this->extraHeaders['Prefer'] = 'count=exact';

        $response = $this->request();

        $range = $response->header('Content-Range');
        $total = $range && str_contains($range, '/') ? (int) substr($range, strrpos($range, '/') + 1) : null;

        return [
            'data' => $response->json() ?? [],
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $total !== null ? (int) ceil(max($total, 1) / $perPage) : null,
        ];
    }

    /**
     * Execute and return a single record, throwing if not exactly one row is returned.
     */
    public function single(): array
    {
        $this->extraHeaders['Accept'] = 'application/vnd.pgrst.object+json';

        return $this->request()->json();
    }

    /**
     * Execute and return a single record or null if none found.
     */
    public function maybeSingle(): ?array
    {
        $this->limit(1);

        $rows = $this->request()->json();

        return $rows[0] ?? null;
    }

    public function get(): Collection
    {
        return collect($this->request()->json() ?? []);
    }

    /**
     * Like get(), but maps each row into the given SupabaseResource subclass.
     *
     * @param  class-string<SupabaseResource>  $resource
     * @return Collection<int, SupabaseResource>
     */
    public function getAs(string $resource): Collection
    {
        return $this->get()->map(fn (array $row) => $resource::fromArray($row));
    }

    /**
     * Like single(), but maps the row into the given SupabaseResource subclass.
     *
     * @param  class-string<SupabaseResource>  $resource
     */
    public function singleAs(string $resource): SupabaseResource
    {
        return $resource::fromArray($this->single());
    }

    /**
     * Like maybeSingle(), but maps the row into the given SupabaseResource subclass.
     *
     * @param  class-string<SupabaseResource>  $resource
     */
    public function maybeSingleAs(string $resource): ?SupabaseResource
    {
        $row = $this->maybeSingle();

        return $row === null ? null : $resource::fromArray($row);
    }

    protected function formatValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    protected function buildQuery(): array
    {
        $query = ['select' => $this->select];

        foreach ($this->filters as $column => $condition) {
            $query[$column] = $condition;
        }

        if ($this->orFilters !== []) {
            $query['or'] = '('.implode('),(', $this->orFilters).')';
        }

        if ($this->order !== []) {
            $query['order'] = implode(',', $this->order);
        }

        if ($this->limitValue !== null) {
            $query['limit'] = $this->limitValue;
        }

        if ($this->offsetValue !== null) {
            $query['offset'] = $this->offsetValue;
        }

        return $query;
    }

    /**
     * @throws SupabasePostgrestException
     */
    protected function request(): Response
    {
        $uri = '/rest/v1/'.$this->table;
        $query = $this->buildQuery();

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $response = match ($this->method) {
            'POST' => $this->client->post("{$uri}?{$queryString}", $this->payload ?? [], $this->extraHeaders),
            'PATCH' => $this->client->patch("{$uri}?{$queryString}", $this->payload ?? [], $this->extraHeaders),
            'DELETE' => $this->client->delete("{$uri}?{$queryString}", [], $this->extraHeaders),
            default => $this->client->get($uri, $query, $this->extraHeaders),
        };

        if ($response->failed()) {
            throw SupabasePostgrestException::fromResponse($response);
        }

        return $response;
    }
}
