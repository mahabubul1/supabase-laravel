<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Vector;

use BlackDevs\SupabaseLaravel\Clients\SupabaseHttpClient;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Helpers for storing and querying pgvector embeddings (AI/RAG use cases).
 *
 * PostgREST cannot express pgvector's distance operators (<->, <=>, <#>)
 * directly, so similarity search goes through an RPC to a Postgres matching
 * function — the standard Supabase pattern. Example function:
 *
 *   create function match_documents(
 *     query_embedding vector(1536),
 *     match_threshold float,
 *     match_count int
 *   ) returns table (id bigint, content text, similarity float)
 *   language sql stable as $$
 *     select id, content, 1 - (embedding <=> query_embedding) as similarity
 *     from documents
 *     where 1 - (embedding <=> query_embedding) > match_threshold
 *     order by embedding <=> query_embedding
 *     limit match_count;
 *   $$;
 */
class SupabaseVectorClient
{
    public function __construct(
        protected SupabaseHttpClient $client,
    ) {}

    /**
     * Insert or update a row containing an embedding column, formatting the
     * embedding array as a pgvector literal.
     *
     * @param  array<string, mixed>  $attributes  Other column values for the row.
     * @param  array<int, float>  $embedding
     * @param  array<int, string>  $onConflict  Columns to upsert on (e.g. ['id']).
     */
    public function upsert(string $table, array $attributes, string $embeddingColumn, array $embedding, array $onConflict = ['id']): array
    {
        $row = array_merge($attributes, [
            $embeddingColumn => $this->toVectorLiteral($embedding),
        ]);

        return $this->client->from($table)->upsert($row, $onConflict)->get()->all();
    }

    /**
     * Run a similarity search via a Postgres matching function (RPC), since
     * PostgREST cannot express pgvector distance operators directly.
     *
     * @param  array<int, float>  $queryEmbedding
     * @param  array<string, mixed>  $extraParams  Additional params the RPC function accepts.
     */
    public function search(string $matchFunction, array $queryEmbedding, int $matchCount = 10, array $extraParams = []): Collection
    {
        $result = $this->client->rpc($matchFunction, array_merge([
            'query_embedding' => $this->toVectorLiteral($queryEmbedding),
            'match_count' => $matchCount,
        ], $extraParams));

        return collect($result ?? []);
    }

    /**
     * Format a PHP float array as a pgvector text literal, e.g. "[0.1,0.2,0.3]".
     *
     * @param  array<int, float>  $embedding
     */
    public function toVectorLiteral(array $embedding): string
    {
        if ($embedding === []) {
            throw new InvalidArgumentException('Embedding vector cannot be empty.');
        }

        return '['.implode(',', array_map(fn (float $value) => rtrim(rtrim(sprintf('%.8f', $value), '0'), '.'), $embedding)).']';
    }
}
