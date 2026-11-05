<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Storage;

use BlackDevs\SupabaseLaravel\Exceptions\SupabaseStorageException;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;

/**
 * Adapts SupabaseStorageClient to Flysystem so it can back Storage::disk('supabase').
 */
class SupabaseFlysystemAdapter implements FilesystemAdapter
{
    public function __construct(
        protected SupabaseStorageClient $client,
        protected string $bucket,
    ) {}

    public function fileExists(string $path): bool
    {
        try {
            $listing = $this->client->list($this->bucket, dirname($path) === '.' ? '' : dirname($path));

            return collect($listing)->contains(fn (array $item) => ($item['name'] ?? null) === basename($path));
        } catch (SupabaseStorageException $exception) {
            throw UnableToCheckExistence::forLocation($path, $exception);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            $listing = $this->client->list($this->bucket, $path);

            return $listing !== [];
        } catch (SupabaseStorageException $exception) {
            throw UnableToCheckExistence::forLocation($path, $exception);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $this->client->upload($this->bucket, $path, $contents, $config->get('mimetype', 'application/octet-stream'), true);
        } catch (SupabaseStorageException $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($path, stream_get_contents($contents), $config);
    }

    public function read(string $path): string
    {
        try {
            return $this->client->download($this->bucket, $path);
        } catch (SupabaseStorageException $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        try {
            $this->client->delete($this->bucket, [$path]);
        } catch (SupabaseStorageException $exception) {
            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            $items = $this->client->list($this->bucket, $path);
            $paths = collect($items)->map(fn (array $item) => trim($path.'/'.$item['name'], '/'))->all();

            if ($paths !== []) {
                $this->client->delete($this->bucket, $paths);
            }
        } catch (SupabaseStorageException $exception) {
            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        // Supabase Storage has no explicit directories; paths are created implicitly on upload.
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Visibility is controlled at the bucket level in Supabase Storage, not per-object.
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        try {
            $items = $this->client->list($this->bucket, dirname($path) === '.' ? '' : dirname($path));
            $item = collect($items)->firstWhere('name', basename($path));

            return new FileAttributes($path, mimeType: $item['metadata']['mimetype'] ?? null);
        } catch (SupabaseStorageException $exception) {
            throw UnableToRetrieveMetadata::mimeType($path, $exception->getMessage(), $exception);
        }
    }

    public function lastModified(string $path): FileAttributes
    {
        try {
            $items = $this->client->list($this->bucket, dirname($path) === '.' ? '' : dirname($path));
            $item = collect($items)->firstWhere('name', basename($path));
            $updatedAt = $item['updated_at'] ?? null;

            return new FileAttributes($path, lastModified: $updatedAt ? strtotime($updatedAt) : null);
        } catch (SupabaseStorageException $exception) {
            throw UnableToRetrieveMetadata::lastModified($path, $exception->getMessage(), $exception);
        }
    }

    public function fileSize(string $path): FileAttributes
    {
        try {
            $items = $this->client->list($this->bucket, dirname($path) === '.' ? '' : dirname($path));
            $item = collect($items)->firstWhere('name', basename($path));

            return new FileAttributes($path, fileSize: $item['metadata']['size'] ?? null);
        } catch (SupabaseStorageException $exception) {
            throw UnableToRetrieveMetadata::fileSize($path, $exception->getMessage(), $exception);
        }
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $items = $this->client->list($this->bucket, $path);

        foreach ($items as $item) {
            $itemPath = trim($path.'/'.$item['name'], '/');

            yield ($item['id'] ?? null) === null
                ? new DirectoryAttributes($itemPath)
                : new FileAttributes($itemPath, fileSize: $item['metadata']['size'] ?? null);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->client->move($this->bucket, $source, $destination);
        } catch (SupabaseStorageException $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->client->copy($this->bucket, $source, $destination);
        } catch (SupabaseStorageException $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
    }
}
