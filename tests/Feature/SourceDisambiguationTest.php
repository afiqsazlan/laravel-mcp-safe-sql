<?php

declare(strict_types=1);

use Afiqsazlan\SafeSql\Instructions\InstructionComposer;
use Afiqsazlan\SafeSql\Profiles\Profile;
use Afiqsazlan\SafeSql\Tools\DescribeTableTool;
use Afiqsazlan\SafeSql\Tools\ExecuteSqlTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;

beforeEach(function () {
    config()->set('safe-sql.profiles.prod', [
        'label' => 'production', 'connection' => 'testing',
        'anonymize' => true, 'tools' => ['sql', 'schema'],
    ]);
    config()->set('safe-sql.profiles.qa', [
        'label' => 'staging', 'connection' => 'testing',
        'anonymize' => false, 'tools' => ['sql', 'schema'],
    ]);

    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('status');
    });

    DB::table('customers')->insert(['status' => 'active']);
});

describe('source description', function () {
    it('names the environment and how values are treated', function () {
        expect(Profile::make('prod')->sourceDescription())->toBe('production (values pseudonymized)')
            ->and(Profile::make('qa')->sourceDescription())
            ->toBe('staging (literal values, no pseudonymization)');
    });

    it('falls back to the profile name when no label is set', function () {
        config()->set('safe-sql.profiles.unlabelled', ['connection' => 'testing']);

        expect(Profile::make('unlabelled')->sourceDescription())
            ->toBe('unlabelled (values pseudonymized)');
    });
});

describe('tool descriptions', function () {
    it('states the source, since the model picks between identical tool names', function () {
        expect((new ExecuteSqlTool(Profile::make('prod')))->description())
            ->toStartWith('Runs against: production (values pseudonymized)')
            ->and((new ExecuteSqlTool(Profile::make('qa')))->description())
            ->toStartWith('Runs against: staging (literal values');
    });

    it('states it on the schema tool too', function () {
        expect((new DescribeTableTool(Profile::make('prod')))->description())
            ->toContain('production (values pseudonymized)');
    });

    it('still honours a fully custom description', function () {
        config()->set('safe-sql.profiles.prod.descriptions', ['sql' => 'Custom.']);

        expect((new ExecuteSqlTool(Profile::make('prod')))->description())->toBe('Custom.');
    });
});

describe('tool responses', function () {
    it('reports which database answered', function () {
        $tool = new ExecuteSqlTool(Profile::make('prod'));

        $response = app()->call([$tool, 'handle'], [
            'request' => new Request(['query' => 'SELECT status FROM customers']),
        ]);

        $result = json_decode((string) $response->content(), true);

        // A query answered by the wrong server is otherwise invisible: the
        // rows look perfectly plausible either way.
        expect($result['source'])->toBe('production (values pseudonymized)')
            ->and($result['profile'])->toBe('prod');
    });

    it('distinguishes two servers reading the same connection', function () {
        $prod = new ExecuteSqlTool(Profile::make('prod'));
        $qa = new ExecuteSqlTool(Profile::make('qa'));

        $of = fn ($tool) => json_decode((string) app()->call([$tool, 'handle'], [
            'request' => new Request(['query' => 'SELECT status FROM customers']),
        ])->content(), true)['source'];

        expect($of($prod))->not->toBe($of($qa));
    });
});

describe('instructions', function () {
    it('tells the agent which database it is reading', function () {
        expect((new InstructionComposer)->compose(Profile::make('prod')))
            ->toContain('This server reads **production (values pseudonymized)**');
    });

    it('warns that answering from the wrong environment looks plausible', function () {
        $instructions = (new InstructionComposer)->compose(Profile::make('qa'));

        expect($instructions)->toContain('plausible number that is simply false')
            ->and($instructions)->toContain('ask which before querying');
    });

    it('forbids mixing rows or pseudonyms across servers', function () {
        expect((new InstructionComposer)->compose(Profile::make('prod')))
            ->toContain('Never combine rows from two servers')
            ->toContain('unrelated databases, unrelated salts');
    });

    it('is included even for a profile that does not anonymize', function () {
        expect((new InstructionComposer)->compose(Profile::make('qa')))
            ->toContain('Which database this is');
    });
});
