<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

use function Laravel\Prompts\select;

/**
 * Gets an application from "composer require" to a working endpoint.
 *
 * The steps are individually trivial and collectively easy to get wrong in an
 * order that half-works, so they are done here in one pass with each one
 * reported. Nothing is overwritten without asking.
 */
class InstallCommand extends Command
{
    protected $signature = 'safe-sql:install
        {--profile=research : Profile the generated server should serve}
        {--connection= : Database connection the profile should read}
        {--routes : Also publish the example route file}
        {--force : Overwrite an existing server class}';

    protected $description = 'Publish config and scaffold a safe-sql MCP server';

    public function handle(): int
    {
        $profile = (string) $this->option('profile');

        $this->components->info("Installing safe-sql for the [{$profile}] profile.");

        $this->publishConfig();

        $connection = $this->resolveConnection();

        if ($connection !== null) {
            $this->writeEnv($profile, $connection);
        }

        $server = $this->scaffoldServer($profile);

        if ($this->option('routes')) {
            $this->callSilently('vendor:publish', ['--tag' => 'safe-sql-routes']);
            $this->components->info('Published routes/safe-sql.php.');
        }

        $this->nextSteps($profile, $server, $connection);

        return self::SUCCESS;
    }

    protected function publishConfig(): void
    {
        if (file_exists(config_path('safe-sql.php'))) {
            $this->components->twoColumnDetail('config/safe-sql.php', '<fg=yellow>already present</>');

            return;
        }

        $this->callSilently('vendor:publish', ['--tag' => 'safe-sql-config']);
        $this->components->twoColumnDetail('config/safe-sql.php', '<fg=green>published</>');
    }

    protected function resolveConnection(): ?string
    {
        if (($given = $this->option('connection')) !== null) {
            return (string) $given;
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        /** @var array<string, mixed> $connections */
        $connections = Config::get('database.connections', []);
        $names = array_keys($connections);

        if ($names === []) {
            return null;
        }

        return (string) select(
            label: 'Which database connection should this profile read?',
            options: $names,
            default: (string) Config::get('database.default'),
            hint: 'Point this at a read replica if you have one, with a read-only user.',
        );
    }

    /**
     * Record the connection in .env rather than editing the published config,
     * which is the user's file to own from here on.
     */
    protected function writeEnv(string $profile, string $connection): void
    {
        $key = 'SAFE_SQL_'.Str::upper(Str::snake($profile)).'_CONNECTION';
        $path = base_path('.env');

        if (! file_exists($path)) {
            $this->components->twoColumnDetail($key, '<fg=yellow>no .env — set this yourself</>');

            return;
        }

        $contents = (string) file_get_contents($path);

        if (preg_match("/^{$key}=/m", $contents) === 1) {
            $this->components->twoColumnDetail($key, '<fg=yellow>already set</>');

            return;
        }

        file_put_contents($path, rtrim($contents, "\n")."\n{$key}={$connection}\n");
        $this->components->twoColumnDetail($key, "<fg=green>{$connection}</>");
    }

    protected function scaffoldServer(string $profile): string
    {
        $class = Str::studly($profile).'Server';
        $namespace = 'App\\Mcp\\Servers';
        $path = app_path('Mcp/Servers/'.$class.'.php');

        if (file_exists($path) && ! $this->option('force')) {
            $this->components->twoColumnDetail($namespace.'\\'.$class, '<fg=yellow>already exists</>');

            return $class;
        }

        $stub = (string) file_get_contents(__DIR__.'/../../stubs/server.stub');

        $contents = str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ name }}', '{{ profile }}'],
            [$namespace, $class, Str::headline($profile), $profile],
            $stub
        );

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $contents);
        $this->components->twoColumnDetail($namespace.'\\'.$class, '<fg=green>created</>');

        return $class;
    }

    protected function nextSteps(string $profile, string $server, ?string $connection): void
    {
        $this->newLine();
        $this->components->info('Next:');

        $this->line('  <fg=gray>1.</> Register the endpoint, with your own middleware:');
        $this->newLine();
        $this->line("       <fg=cyan>use App\\Mcp\\Servers\\{$server};</>");
        $this->line('       <fg=cyan>use Afiqsazlan\\SafeSql\\Http\\OAuthRoutes;</>');
        $this->newLine();
        $this->line('       <fg=cyan>OAuthRoutes::register();</>');
        $this->line("       <fg=cyan>Route::middleware(['auth:oauth', 'scope:mcp:use'])</>");
        $this->line("       <fg=cyan>    ->group(fn () => Mcp::web('mcp/{$profile}', {$server}::class));</>");
        $this->newLine();

        $this->line('  <fg=gray>2.</> Classify your columns, or most of them come back as tokens:');
        $this->newLine();
        $this->line("       <fg=cyan>php artisan safe-sql:classify --profile={$profile}</>");
        $this->newLine();

        $this->line('  <fg=gray>3.</> Give the connection a read-only database user. The query');
        $this->line('     validator is defense in depth, not a sandbox.');

        if ($connection === null) {
            $this->newLine();
            $this->components->warn(
                'No connection was set. Add SAFE_SQL_'.Str::upper(Str::snake($profile))
                .'_CONNECTION to your .env, or edit config/safe-sql.php.'
            );
        }

        if (! class_exists(Passport::class)) {
            $this->newLine();
            $this->components->warn('Passport is not installed, so remote OAuth will not work yet.');
            $this->line('     A local agent does not need it:');
            $this->newLine();
            $this->line("       <fg=cyan>Mcp::local('{$profile}', {$server}::class);</>");
        }
    }
}
