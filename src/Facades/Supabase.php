<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Facades;

use BlackDevs\SupabaseLaravel\Auth\SupabaseAuthClient;
use BlackDevs\SupabaseLaravel\Clients\SupabaseHttpClient;
use BlackDevs\SupabaseLaravel\Functions\SupabaseFunctionsClient;
use BlackDevs\SupabaseLaravel\Postgrest\PostgrestQueryBuilder;
use BlackDevs\SupabaseLaravel\Realtime\RealtimeChannel;
use BlackDevs\SupabaseLaravel\Storage\SupabaseStorageClient;
use BlackDevs\SupabaseLaravel\SupabaseConnectionConfig;
use BlackDevs\SupabaseLaravel\SupabaseManager;
use BlackDevs\SupabaseLaravel\Vector\SupabaseVectorClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static SupabaseConnectionConfig connection(?string $name = null)
 * @method static SupabaseHttpClient serviceRole(?string $connection = null)
 * @method static SupabaseHttpClient asUser(string $accessToken, ?string $connection = null)
 * @method static SupabaseHttpClient anonymous(?string $connection = null)
 * @method static PostgrestQueryBuilder table(string $table, ?string $connection = null)
 * @method static mixed rpc(string $function, array $params = [], ?string $connection = null)
 * @method static SupabaseAuthClient auth(?string $accessToken = null, ?string $connection = null)
 * @method static SupabaseStorageClient storage(?string $accessToken = null, ?string $connection = null)
 * @method static RealtimeChannel channel(string $topic, ?string $connection = null)
 * @method static SupabaseFunctionsClient functions(?string $accessToken = null, ?string $connection = null)
 * @method static mixed invoke(string $functionName, array $payload = [], array $headers = [], ?string $accessToken = null, ?string $connection = null)
 * @method static void fake(array $responses = [], ?string $connection = null)
 * @method static SupabaseVectorClient vector(?string $connection = null)
 *
 * @see SupabaseManager
 */
class Supabase extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'supabase';
    }
}
