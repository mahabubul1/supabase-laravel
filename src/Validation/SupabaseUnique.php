<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Validation;

use BlackDevs\SupabaseLaravel\Facades\Supabase;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a value does not already exist in a Supabase table,
 * mirroring Laravel's own `Rule::unique()` for data that lives in Supabase
 * rather than the local database.
 *
 * ```php
 * 'email' => ['required', 'email', (new SupabaseUnique('users', 'email'))->ignore($user->id)],
 * ```
 *
 * Runs against the service-role client so RLS never hides a conflicting row
 * from the uniqueness check.
 */
class SupabaseUnique implements ValidationRule
{
    protected mixed $ignoreValue = null;

    protected string $ignoreColumn = 'id';

    protected ?string $connection = null;

    public function __construct(
        protected string $table,
        protected ?string $column = null,
    ) {}

    public function ignore(mixed $value, string $column = 'id'): static
    {
        $this->ignoreValue = $value;
        $this->ignoreColumn = $column;

        return $this;
    }

    public function connection(string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $column = $this->column ?? $attribute;

        $query = Supabase::table($this->table, $this->connection)->eq($column, $value);

        if ($this->ignoreValue !== null) {
            $query->neq($this->ignoreColumn, $this->ignoreValue);
        }

        if ($query->maybeSingle() !== null) {
            $fail(trans('validation.unique', ['attribute' => $attribute]));
        }
    }
}
