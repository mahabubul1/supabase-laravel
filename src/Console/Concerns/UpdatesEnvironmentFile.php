<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Console\Concerns;

trait UpdatesEnvironmentFile
{
    /**
     * Set or replace KEY=value lines in an .env-style file, preserving everything else.
     * Keys mapped to null or '' are skipped, not written.
     *
     * @param  array<string, ?string>  $values
     */
    protected function updateEnvironmentFile(string $path, array $values): void
    {
        $contents = file_exists($path) ? file_get_contents($path) : '';

        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $line = $key.'='.$this->escapeEnvValue($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*/m';

            $contents = preg_match($pattern, $contents) === 1
                ? preg_replace($pattern, $line, $contents)
                : rtrim($contents)."\n".$line."\n";
        }

        file_put_contents($path, ltrim($contents, "\n"));
    }

    protected function escapeEnvValue(string $value): string
    {
        return preg_match('/\s|#|"/', $value) === 1
            ? '"'.str_replace('"', '\"', $value).'"'
            : $value;
    }
}
