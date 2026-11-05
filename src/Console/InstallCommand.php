<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Console;

use BlackDevs\SupabaseLaravel\Console\Concerns\UpdatesEnvironmentFile;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Interactively configure this application's Supabase connection: prompts
 * for the project URL/keys, writes them to .env, publishes the config file,
 * and optionally runs a connectivity check.
 */
class InstallCommand extends Command
{
    use UpdatesEnvironmentFile;

    protected $signature = 'supabase:install
        {--connection=main : The config/supabase.php connection name being configured}
        {--force : Overwrite config/supabase.php if it has already been published}';

    protected $description = "Interactively configure this application's Supabase connection";

    public function handle(): int
    {
        $connection = (string) $this->option('connection');

        $this->components->info("Configuring the Supabase connection [{$connection}]");

        $url = text(
            label: 'Supabase Project URL',
            placeholder: 'https://xxxxxxxxxxxx.supabase.co',
            required: true,
            validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_URL) ? null : 'Enter a valid URL.',
        );

        $anonKey = password(label: 'Supabase anon (public) key', required: true);

        $serviceRoleKey = password(
            label: 'Supabase service_role key (optional — server-side only, bypasses RLS)',
        );

        $jwtSecret = password(
            label: "Supabase JWT secret (optional — required for the 'supabase' auth guard)",
        );

        $debug = confirm(
            label: 'Enable verbose Supabase request/response debug logging?',
            default: false,
        );

        $this->updateEnvironmentFile(base_path('.env'), [
            'SUPABASE_URL' => $url,
            'SUPABASE_ANON_KEY' => $anonKey,
            'SUPABASE_SERVICE_ROLE_KEY' => $serviceRoleKey,
            'SUPABASE_JWT_SECRET' => $jwtSecret,
            'SUPABASE_DEBUG' => $debug ? 'true' : 'false',
        ]);

        $this->components->info('Updated .env');

        config([
            "supabase.connections.{$connection}.url" => $url,
            "supabase.connections.{$connection}.anon_key" => $anonKey,
            "supabase.connections.{$connection}.service_role_key" => $serviceRoleKey,
            "supabase.connections.{$connection}.jwt_secret" => $jwtSecret,
            'supabase.logging.debug' => $debug,
        ]);

        if (! file_exists(config_path('supabase.php')) || $this->option('force')) {
            $this->callSilently('vendor:publish', array_filter([
                '--tag' => 'supabase-config',
                '--force' => $this->option('force'),
            ]));

            $this->components->info('Published config/supabase.php');
        }

        if ($connection !== 'main') {
            $this->components->warn(
                'config/supabase.php only wires the [main] connection to these env vars by default — '.
                "add a [{$connection}] entry to its \"connections\" array manually.",
            );
        }

        note('Run `php artisan supabase:test` any time to verify connectivity.');

        if (confirm('Run a connection test now?', default: true)) {
            return $this->call('supabase:test', ['--connection' => $connection === 'main' ? null : $connection]);
        }

        return self::SUCCESS;
    }
}
