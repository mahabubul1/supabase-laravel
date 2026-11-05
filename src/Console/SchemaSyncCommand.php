<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Console;

use BlackDevs\SupabaseLaravel\Exceptions\SupabasePostgrestException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Pulls the remote schema from PostgREST's OpenAPI description and
 * generates a typed SupabaseResource DTO per table/view — giving IDE
 * autocomplete on Supabase rows without a direct Postgres connection.
 *
 * PostgREST exposes this schema to any client (anon or service key) via
 * `Accept: application/openapi+json` on its root endpoint, so this works
 * over the same HTTP API the rest of the package uses.
 */
class SchemaSyncCommand extends Command
{
    protected $signature = 'supabase:schema-sync
        {--connection= : The Supabase connection to introspect (default: config default)}
        {--path= : Directory to write generated classes into (default: app/Supabase/Resources)}
        {--namespace= : Namespace for generated classes (default: App\\Supabase\\Resources)}
        {--only= : Comma-separated list of table names to generate (default: all)}
        {--force : Overwrite existing files}';

    protected $description = 'Generate typed DTO classes from the remote Supabase/PostgREST schema';

    /**
     * @var array<string, string>
     */
    protected const TYPE_MAP = [
        'integer' => 'int',
        'number' => 'float',
        'boolean' => 'bool',
        'array' => 'array',
        'object' => 'array',
    ];

    public function handle(): int
    {
        $connection = $this->option('connection');
        $namespace = rtrim((string) ($this->option('namespace') ?: 'App\\Supabase\\Resources'), '\\');
        $directory = rtrim((string) ($this->option('path') ?: app_path('Supabase/Resources')), '/');
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));

        $tables = $this->fetchTableDefinitions($connection);

        if ($tables === null) {
            return self::FAILURE;
        }

        if ($only !== []) {
            $tables = array_intersect_key($tables, array_flip($only));
        }

        if ($tables === []) {
            $this->components->warn('No matching tables found in the remote schema.');

            return self::SUCCESS;
        }

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->components->error("Could not create directory {$directory}.");

            return self::FAILURE;
        }

        foreach ($tables as $table => $definition) {
            $this->generateResource($table, $definition, $namespace, $directory);
        }

        $this->components->info(sprintf('Generated %d resource class(es) in %s', count($tables), $directory));

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{properties: array<string, array<string, mixed>>, required: array<int, string>}>|null
     */
    protected function fetchTableDefinitions(?string $connection): ?array
    {
        try {
            $client = app('supabase')->serviceRole($connection);
            $response = $client->get('', [], ['Accept' => 'application/openapi+json']);
        } catch (\Throwable $exception) {
            $this->components->error("Could not reach Supabase: {$exception->getMessage()}");

            return null;
        }

        if ($response->failed()) {
            $this->components->error('Schema introspection request failed: '.SupabasePostgrestException::fromResponse($response)->getMessage());

            return null;
        }

        $schema = $response->json();
        $definitions = $schema['definitions'] ?? null;

        if (! is_array($definitions) || $definitions === []) {
            $this->components->error('The remote schema response did not contain any table definitions.');

            return null;
        }

        return $definitions;
    }

    /**
     * @param  array{properties: array<string, array<string, mixed>>, required?: array<int, string>}  $definition
     */
    protected function generateResource(string $table, array $definition, string $namespace, string $directory): void
    {
        $className = Str::studly(Str::singular($table));
        $path = "{$directory}/{$className}.php";

        if (file_exists($path) && ! $this->option('force')) {
            $this->components->warn("Skipped {$className} (already exists, use --force to overwrite)");

            return;
        }

        $required = $definition['required'] ?? [];
        $properties = $definition['properties'] ?? [];

        $lines = [];

        foreach ($properties as $column => $schema) {
            $type = self::TYPE_MAP[$schema['type'] ?? 'string'] ?? 'string';
            $nullable = ! in_array($column, $required, true);
            $declared = $nullable ? "?{$type}" : $type;
            $default = $nullable ? ' = null' : '';

            $description = $schema['description'] ?? null;
            if ($description) {
                $lines[] = "    /** {$description} */";
            }

            $lines[] = "    public {$declared} \${$column}{$default};";
        }

        $body = implode("\n", $lines);

        $contents = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use BlackDevs\SupabaseLaravel\Support\SupabaseResource;

        /**
         * Auto-generated from the remote Supabase schema (table: {$table}).
         * Do not edit by hand — rerun `php artisan supabase:schema-sync`.
         */
        class {$className} extends SupabaseResource
        {
        {$body}
        }

        PHP;

        file_put_contents($path, $contents);

        $this->components->info("Generated {$className}");
    }
}
