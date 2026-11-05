<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel;

use BlackDevs\SupabaseLaravel\Auth\SupabaseTokenGuard;
use BlackDevs\SupabaseLaravel\Broadcasting\SupabaseBroadcaster;
use BlackDevs\SupabaseLaravel\Cache\SupabaseStore;
use BlackDevs\SupabaseLaravel\Console\InstallCommand;
use BlackDevs\SupabaseLaravel\Console\MakeFunctionCommand;
use BlackDevs\SupabaseLaravel\Console\MakeSeederCommand;
use BlackDevs\SupabaseLaravel\Console\MakeTableCommand;
use BlackDevs\SupabaseLaravel\Console\MigrateTableCommand;
use BlackDevs\SupabaseLaravel\Console\PushFunctionsCommand;
use BlackDevs\SupabaseLaravel\Console\RollbackFunctionCommand;
use BlackDevs\SupabaseLaravel\Console\RollbackTableCommand;
use BlackDevs\SupabaseLaravel\Console\SchemaSyncCommand;
use BlackDevs\SupabaseLaravel\Console\SeedCommand;
use BlackDevs\SupabaseLaravel\Console\TestCommand;
use BlackDevs\SupabaseLaravel\Http\Middleware\VerifySupabaseToken;
use BlackDevs\SupabaseLaravel\Http\Middleware\VerifySupabaseWebhookSignature;
use BlackDevs\SupabaseLaravel\Storage\SupabaseFlysystemAdapter;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem as Flysystem;

class SupabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/supabase.php', 'supabase');

        $this->app->singleton('supabase', function ($app) {
            return new SupabaseManager($app['config']['supabase']);
        });

        $this->app->alias('supabase', SupabaseManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/supabase.php' => config_path('supabase.php'),
            ], 'supabase-config');

            $this->commands([
                InstallCommand::class,
                TestCommand::class,
                MakeFunctionCommand::class,
                SchemaSyncCommand::class,
                PushFunctionsCommand::class,
                RollbackFunctionCommand::class,
                MakeTableCommand::class,
                MigrateTableCommand::class,
                RollbackTableCommand::class,
                MakeSeederCommand::class,
                SeedCommand::class,
            ]);
        }

        Route::aliasMiddleware('supabase.auth', VerifySupabaseToken::class);
        Route::aliasMiddleware('supabase.webhook', VerifySupabaseWebhookSignature::class);

        Auth::extend('supabase', function ($app, string $name, array $authConfig) {
            $manager = $app->make('supabase');
            $jwtSecret = $manager->connection($authConfig['connection'] ?? null)->jwtSecret ?? '';

            return new SupabaseTokenGuard($app->make('request'), $jwtSecret);
        });

        Storage::extend('supabase', function ($app, array $diskConfig) {
            $manager = $app->make('supabase');
            $storageClient = $manager->storage(null, $diskConfig['connection'] ?? null);
            $adapter = new SupabaseFlysystemAdapter($storageClient, $diskConfig['bucket']);

            return new FilesystemAdapter(new Flysystem($adapter), $adapter, $diskConfig);
        });

        Broadcast::extend('supabase', function ($app, array $broadcastConfig) {
            $manager = $app->make('supabase');

            return new SupabaseBroadcaster($manager->serviceRole($broadcastConfig['connection'] ?? null));
        });

        Cache::extend('supabase', function ($app, array $cacheConfig) {
            $manager = $app->make('supabase');

            return new CacheRepository(new SupabaseStore(
                $manager,
                $cacheConfig['table'] ?? 'cache',
                $cacheConfig['connection'] ?? null,
                $cacheConfig['prefix'] ?? '',
            ));
        });
    }
}
