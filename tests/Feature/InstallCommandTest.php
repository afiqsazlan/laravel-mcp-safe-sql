<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

afterEach(function () {
    // Everything the command writes lands in Testbench's shared app skeleton,
    // which outlives the test. A published config/safe-sql.php left behind
    // there silently overrides the package config for every later test.
    File::deleteDirectory(app_path('Mcp'));
    File::delete(config_path('safe-sql.php'));
    File::delete(base_path('routes/safe-sql.php'));
});

it('scaffolds a server class for the profile', function () {
    $this->artisan('safe-sql:install --profile=research --connection=testing')
        ->assertSuccessful();

    $path = app_path('Mcp/Servers/ResearchServer.php');

    expect(File::exists($path))->toBeTrue()
        ->and(File::get($path))
        ->toContain('class ResearchServer extends SqlMcpServer')
        ->toContain("protected string \$profile = 'research';")
        ->toContain('namespace App\Mcp\Servers;');
});

it('names the class after the profile', function () {
    $this->artisan('safe-sql:install --profile=qa_debug --connection=testing')
        ->assertSuccessful();

    expect(File::exists(app_path('Mcp/Servers/QaDebugServer.php')))->toBeTrue()
        ->and(File::get(app_path('Mcp/Servers/QaDebugServer.php')))
        ->toContain("protected string \$profile = 'qa_debug';");
});

it('does not overwrite an existing server without --force', function () {
    $this->artisan('safe-sql:install --profile=research --connection=testing')->assertSuccessful();

    File::put(app_path('Mcp/Servers/ResearchServer.php'), '<?php // hand-edited');

    $this->artisan('safe-sql:install --profile=research --connection=testing')->assertSuccessful();

    expect(File::get(app_path('Mcp/Servers/ResearchServer.php')))->toBe('<?php // hand-edited');
});

it('overwrites when --force is given', function () {
    $this->artisan('safe-sql:install --profile=research --connection=testing')->assertSuccessful();
    File::put(app_path('Mcp/Servers/ResearchServer.php'), '<?php // hand-edited');

    $this->artisan('safe-sql:install --profile=research --connection=testing --force')->assertSuccessful();

    expect(File::get(app_path('Mcp/Servers/ResearchServer.php')))->toContain('SqlMcpServer');
});

it('records the connection in .env', function () {
    $env = base_path('.env');
    File::put($env, "APP_ENV=testing\n");

    $this->artisan('safe-sql:install --profile=research --connection=mysql_replica')
        ->assertSuccessful();

    expect(File::get($env))->toContain('SAFE_SQL_RESEARCH_CONNECTION=mysql_replica');

    File::delete($env);
});

it('does not clobber a connection already set in .env', function () {
    $env = base_path('.env');
    File::put($env, "SAFE_SQL_RESEARCH_CONNECTION=chosen_by_hand\n");

    $this->artisan('safe-sql:install --profile=research --connection=something_else')
        ->assertSuccessful();

    expect(File::get($env))->toContain('chosen_by_hand')
        ->and(File::get($env))->not->toContain('something_else');

    File::delete($env);
});

it('points the user at the classifier, since that is the real first step', function () {
    $this->artisan('safe-sql:install --profile=research --connection=testing')
        ->expectsOutputToContain('safe-sql:classify --profile=research')
        ->assertSuccessful();
});

it('warns when Passport is absent, and offers the local transport instead', function () {
    // Remote MCP needs OAuth; a local agent does not. Saying so here saves
    // someone concluding the package is broken.
    $this->artisan('safe-sql:install --profile=research --connection=testing')
        ->expectsOutputToContain('Passport is not installed')
        ->expectsOutputToContain('Mcp::local')
        ->assertSuccessful();
});

it('tells the user that authorization is theirs to define', function () {
    // "It has OAuth" is the most likely wrong conclusion someone draws here:
    // OAuth establishes identity and says nothing about permission.
    $this->artisan('safe-sql:install --profile=research --connection=testing')
        ->expectsOutputToContain('can:access-research')
        ->expectsOutputToContain('the gate says whether they may')
        ->assertSuccessful();
});
