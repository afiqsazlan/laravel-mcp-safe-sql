<?php

declare(strict_types=1);

use Afiqsazlan\SafeSql\Exceptions\UnknownProfileException;
use Afiqsazlan\SafeSql\Profiles\Profile;
use Afiqsazlan\SafeSql\Servers\SqlMcpServer;
use Afiqsazlan\SafeSql\Tools\DescribeTableTool;
use Afiqsazlan\SafeSql\Tools\ExecuteSqlTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Transport\FakeTransporter;

beforeEach(function () {
    config()->set('safe-sql.profiles.testing', [
        'connection' => 'testing',
        'anonymize' => true,
        'tools' => ['sql', 'schema'],
    ]);

    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('status');
        $table->timestamps();
    });

    DB::table('customers')->insert([
        ['name' => 'Alice Tan', 'email' => 'alice@example.com', 'status' => 'active',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        ['name' => 'Bob Lim', 'email' => 'bob@example.com', 'status' => 'churned',
            'created_at' => '2026-01-02 00:00:00', 'updated_at' => '2026-01-02 00:00:00'],
    ]);
});

/** @return array<string, mixed> */
function runSql(string $query, string $profile = 'testing'): array
{
    $tool = new ExecuteSqlTool(Profile::make($profile));

    $response = app()->call([$tool, 'handle'], [
        'request' => new Request(['query' => $query]),
    ]);

    return json_decode((string) $response->content(), true) ?: [];
}

describe('profile resolution', function () {
    it('anonymizes unless told otherwise', function () {
        config()->set('safe-sql.profiles.implicit', ['connection' => 'testing']);

        expect(Profile::make('implicit')->anonymize)->toBeTrue();
    });

    it('honours an explicit opt out', function () {
        config()->set('safe-sql.profiles.literal', ['connection' => 'testing', 'anonymize' => false]);

        expect(Profile::make('literal')->anonymize)->toBeFalse();
    });

    it('rejects an unconfigured profile', function () {
        expect(fn () => Profile::make('nope'))
            ->toThrow(UnknownProfileException::class, 'No safe-sql profile named [nope]');
    });
});

describe('tool registration', function () {
    it('exposes only the tools the profile lists', function () {
        $server = new class(new FakeTransporter) extends SqlMcpServer
        {
            protected string $profile = 'testing';

            public function toolsForTest(): array
            {
                $this->boot();

                return $this->tools;
            }
        };

        expect($server->toolsForTest())->toHaveCount(2)
            ->and($server->toolsForTest()[0])->toBeInstanceOf(ExecuteSqlTool::class)
            ->and($server->toolsForTest()[1])->toBeInstanceOf(DescribeTableTool::class);
    });

    it('omits telescope from a profile that does not list it', function () {
        // The safety property is declared in config, not an accident of which
        // server class happened to be registered.
        expect(Profile::make('testing')->exposes('telescope'))->toBeFalse();
    });

    it('rejects a profile listing an unknown tool', function () {
        config()->set('safe-sql.profiles.bad', ['connection' => 'testing', 'tools' => ['sql', 'wat']]);

        $server = new class(new FakeTransporter) extends SqlMcpServer
        {
            protected string $profile = 'bad';

            public function bootForTest(): void
            {
                $this->boot();
            }
        };

        expect(fn () => $server->bootForTest())
            ->toThrow(UnknownProfileException::class, 'unknown tool [wat]');
    });
});

describe('executing sql end to end', function () {
    it('returns rows with metadata', function () {
        $result = runSql('SELECT status FROM customers ORDER BY status');

        expect($result['rowCount'])->toBe(2)
            ->and($result['columns'])->toBe(['status'])
            ->and($result['rows'][0]['status'])->toBe('active');
    });

    it('pseudonymizes PII on the way out', function () {
        $result = runSql('SELECT name, email FROM customers ORDER BY id');

        expect($result['rows'][0]['name'])->toStartWith('[name:')
            ->and($result['rows'][0]['email'])->toStartWith('[email:')
            ->and(json_encode($result))->not->toContain('alice@example.com');
    });

    it('catches PII aliased to a safe label', function () {
        $result = runSql('SELECT email AS id FROM customers ORDER BY customers.id');

        expect($result['rows'][0]['id'])->toStartWith('[email:');
    });

    it('returns an error rather than executing a write', function () {
        $tool = new ExecuteSqlTool(Profile::make('testing'));

        $response = app()->call([$tool, 'handle'], [
            'request' => new Request(['query' => 'DELETE FROM customers']),
        ]);

        expect($response->isError())->toBeTrue()
            ->and((string) $response->content())->toContain('Only SELECT queries are allowed')
            ->and(DB::table('customers')->count())->toBe(2);
    });

    it('leaves aggregates readable', function () {
        $result = runSql('SELECT status, COUNT(*) AS total FROM customers GROUP BY status ORDER BY status');

        expect($result['rows'][0]['total'])->toBe(1)
            ->and($result['rows'][0]['status'])->toBe('active');
    });

    it('does not pseudonymize when the profile opts out', function () {
        config()->set('safe-sql.profiles.literal', [
            'connection' => 'testing', 'anonymize' => false, 'tools' => ['sql'],
        ]);

        $result = runSql('SELECT email FROM customers ORDER BY id', 'literal');

        expect($result['rows'][0]['email'])->toBe('alice@example.com');
    });
});

describe('describing schema', function () {
    it('returns column names unpseudonymized', function () {
        $tool = new DescribeTableTool(Profile::make('testing'));

        $response = app()->call([$tool, 'handle'], [
            'request' => new Request(['table' => 'customers']),
        ]);

        // Schema metadata must survive intact — tokenizing "email" here would
        // defeat the purpose of asking.
        expect((string) $response->content())->toContain('email')
            ->and((string) $response->content())->toContain('name');
    });

    it('reports an unknown table instead of interpolating it into SQL', function () {
        $tool = new DescribeTableTool(Profile::make('testing'));

        $response = app()->call([$tool, 'handle'], [
            'request' => new Request(['table' => '../../etc']),
        ]);

        expect($response->isError())->toBeTrue()
            ->and((string) $response->content())->toContain('not a valid table name');
    });

    it('reports a well-formed table that does not exist', function () {
        $tool = new DescribeTableTool(Profile::make('testing'));

        $response = app()->call([$tool, 'handle'], [
            'request' => new Request(['table' => 'no_such_table']),
        ]);

        expect($response->isError())->toBeTrue()
            ->and((string) $response->content())->toContain('no table named');
    });

    it('lists tables, excluding configured ones', function () {
        $tool = new DescribeTableTool(Profile::make('testing'));

        $response = app()->call([$tool, 'handle'], [
            'request' => new Request([]),
        ]);

        expect((string) $response->content())->toContain('customers');
    });
});
