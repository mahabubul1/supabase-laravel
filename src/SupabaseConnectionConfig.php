<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel;

class SupabaseConnectionConfig
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $anonKey,
        public readonly ?string $serviceRoleKey,
        public readonly ?string $jwtSecret,
        public readonly ?string $dbUrl = null,
    ) {}

    /**
     * @param  array{url: string, anon_key: ?string, service_role_key: ?string, jwt_secret: ?string, db_url?: ?string}  $config
     */
    public static function fromArray(array $config): static
    {
        return new static(
            url: rtrim($config['url'] ?? '', '/'),
            anonKey: $config['anon_key'] ?? null,
            serviceRoleKey: $config['service_role_key'] ?? null,
            jwtSecret: $config['jwt_secret'] ?? null,
            dbUrl: $config['db_url'] ?? null,
        );
    }
}
