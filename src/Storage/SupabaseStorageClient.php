<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Storage;

use BlackDevs\SupabaseLaravel\Clients\SupabaseHttpClient;
use BlackDevs\SupabaseLaravel\Events\FileDeleted;
use BlackDevs\SupabaseLaravel\Events\FileUploaded;
use BlackDevs\SupabaseLaravel\Exceptions\SupabaseStorageException;
use BlackDevs\SupabaseLaravel\SupabaseConnectionConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;

/**
 * Wraps the Supabase Storage HTTP API: objects (upload/download/delete/move/
 * copy/list), public/signed URLs, and bucket management.
 */
class SupabaseStorageClient
{
    public function __construct(
        protected SupabaseHttpClient $client,
        protected SupabaseConnectionConfig $config,
    ) {}

    public function upload(string $bucket, string $path, string $contents, string $contentType = 'application/octet-stream', bool $upsert = false): array
    {
        $response = $this->client->postRaw(
            $this->objectUri($bucket, $path),
            $contents,
            $contentType,
            $upsert ? ['x-upsert' => 'true'] : []
        );

        $data = $this->assert($response);

        event(new FileUploaded($bucket, $path, $data));

        return $data;
    }

    /**
     * Upload an UploadedFile (e.g. straight from a form request) without the
     * caller having to read its contents or resolve its MIME type by hand.
     *
     * @param  string  $directory  Prefix under which the file is stored, no leading/trailing slash.
     */
    public function uploadFile(string $bucket, UploadedFile $file, string $directory = '', bool $upsert = false): array
    {
        $path = trim($directory, '/');
        $path = ($path === '' ? '' : $path.'/').uniqid().'-'.$file->getClientOriginalName();

        return $this->upload(
            $bucket,
            $path,
            file_get_contents($file->getRealPath()),
            $file->getMimeType() ?? 'application/octet-stream',
            $upsert,
        );
    }

    public function download(string $bucket, string $path): string
    {
        $response = $this->client->get($this->objectUri($bucket, $path));

        if ($response->failed()) {
            throw SupabaseStorageException::fromResponse($response);
        }

        return $response->body();
    }

    /**
     * @param  array<int, string>  $paths
     */
    public function delete(string $bucket, array $paths): array
    {
        $response = $this->client->delete("/storage/v1/object/{$bucket}", ['prefixes' => $paths]);

        $data = $this->assert($response);

        event(new FileDeleted($bucket, $paths));

        return $data;
    }

    public function move(string $bucket, string $fromPath, string $toPath): array
    {
        $response = $this->client->post('/storage/v1/object/move', [
            'bucketId' => $bucket,
            'sourceKey' => $fromPath,
            'destinationKey' => $toPath,
        ]);

        return $this->assert($response);
    }

    public function copy(string $bucket, string $fromPath, string $toPath): array
    {
        $response = $this->client->post('/storage/v1/object/copy', [
            'bucketId' => $bucket,
            'sourceKey' => $fromPath,
            'destinationKey' => $toPath,
        ]);

        return $this->assert($response);
    }

    /**
     * @param  array{limit?: int, offset?: int, sortBy?: array{column: string, order: string}}  $options
     */
    public function list(string $bucket, string $path = '', array $options = []): array
    {
        $response = $this->client->post("/storage/v1/object/list/{$bucket}", array_merge([
            'prefix' => $path,
            'limit' => 100,
            'offset' => 0,
        ], $options));

        return $this->assert($response);
    }

    public function getPublicUrl(string $bucket, string $path): string
    {
        return rtrim($this->config->url, '/')."/storage/v1/object/public/{$bucket}/{$path}";
    }

    public function createSignedUrl(string $bucket, string $path, int $expiresInSeconds = 60): string
    {
        $response = $this->client->post("/storage/v1/object/sign/{$bucket}/{$path}", [
            'expiresIn' => $expiresInSeconds,
        ]);

        $data = $this->assert($response);

        return rtrim($this->config->url, '/').($data['signedURL'] ?? '');
    }

    public function createSignedUploadUrl(string $bucket, string $path): array
    {
        $response = $this->client->post("/storage/v1/object/upload/sign/{$bucket}/{$path}");

        return $this->assert($response);
    }

    /**
     * @param  array{public?: bool, fileSizeLimit?: int, allowedMimeTypes?: array<int, string>}  $options
     */
    public function createBucket(string $id, array $options = []): array
    {
        $response = $this->client->post('/storage/v1/bucket', array_merge(['id' => $id, 'name' => $id], $options));

        return $this->assert($response);
    }

    public function getBucket(string $id): array
    {
        $response = $this->client->get("/storage/v1/bucket/{$id}");

        return $this->assert($response);
    }

    public function listBuckets(): array
    {
        $response = $this->client->get('/storage/v1/bucket');

        return $this->assert($response);
    }

    /**
     * @param  array{public?: bool, fileSizeLimit?: int, allowedMimeTypes?: array<int, string>}  $options
     */
    public function updateBucket(string $id, array $options): array
    {
        $response = $this->client->put("/storage/v1/bucket/{$id}", $options);

        return $this->assert($response);
    }

    public function emptyBucket(string $id): array
    {
        $response = $this->client->post("/storage/v1/bucket/{$id}/empty");

        return $this->assert($response);
    }

    public function deleteBucket(string $id): array
    {
        $response = $this->client->delete("/storage/v1/bucket/{$id}");

        return $this->assert($response);
    }

    protected function objectUri(string $bucket, string $path): string
    {
        return "/storage/v1/object/{$bucket}/".ltrim($path, '/');
    }

    protected function assert(Response $response): array
    {
        if ($response->failed()) {
            throw SupabaseStorageException::fromResponse($response);
        }

        return $response->json() ?? [];
    }
}
