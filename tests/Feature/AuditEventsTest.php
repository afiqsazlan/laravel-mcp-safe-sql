<?php

declare(strict_types=1);

use Afiqsazlan\SafeSql\Events\QueryExecuted;
use Afiqsazlan\SafeSql\Events\QueryRejected;
use Afiqsazlan\SafeSql\Events\TelescopeBatchRead;
use Afiqsazlan\SafeSql\Profiles\Profile;
use Afiqsazlan\SafeSql\Tools\ExecuteSqlTool;
use Afiqsazlan\SafeSql\Tools\TelescopeTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;

beforeEach(function () {
    config()->set('safe-sql.profiles.research', [
        'label' => 'production', 'connection' => 'testing',
        'anonymize' => true, 'tools' => ['sql', 'telescope'],
    ]);

    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('email');
        $table->string('status');
    });

    DB::table('customers')->insert([
        ['email' => 'alice@example.com', 'status' => 'active'],
        ['email' => 'bob@example.com', 'status' => 'churned'],
    ]);
});

function runTool(string $query): void
{
    $tool = new ExecuteSqlTool(Profile::make('research'));

    app()->call([$tool, 'handle'], ['request' => new Request(['query' => $query])]);
}

describe('successful queries', function () {
    it('emits an event with who, what and how much', function () {
        Event::fake([QueryExecuted::class]);

        runTool('SELECT status FROM customers');

        Event::assertDispatched(QueryExecuted::class, function (QueryExecuted $e) {
            return $e->profile->name === 'research'
                && $e->sql === 'SELECT status FROM customers'
                && $e->rowCount === 2
                && $e->truncated === false;
        });
    });

    it('carries context ready for a log line', function () {
        Event::fake([QueryExecuted::class]);

        runTool('SELECT status FROM customers');

        Event::assertDispatched(QueryExecuted::class, function (QueryExecuted $e) {
            $context = $e->context();

            return $context['profile'] === 'research'
                && $context['source'] === 'production (values pseudonymized)'
                && $context['connection'] === 'testing'
                && $context['anonymized'] === true
                && $context['row_count'] === 2;
        });
    });

    it('never carries result rows', function () {
        // An audit trail that recorded results would write pseudonymized-for-
        // a-reason data into logs that outlive the session.
        Event::fake([QueryExecuted::class]);

        runTool('SELECT email FROM customers');

        Event::assertDispatched(QueryExecuted::class, function (QueryExecuted $e) {
            $serialized = json_encode($e->context());

            return ! property_exists($e, 'rows')
                && ! str_contains((string) $serialized, 'alice@example.com');
        });
    });

    it('can redact values from the query itself', function () {
        Event::fake([QueryExecuted::class]);

        runTool("SELECT status FROM customers WHERE email = 'alice@example.com'");

        Event::assertDispatched(QueryExecuted::class, function (QueryExecuted $e) {
            return str_contains($e->sql, 'alice@example.com')
                && ! str_contains($e->redactedSql(), 'alice@example.com')
                && str_contains($e->redactedSql(), '[email:');
        });
    });
});

describe('refused queries', function () {
    it('emits a rejection with the reason', function () {
        // A burst of these against one endpoint is worth a human looking at.
        Event::fake([QueryRejected::class, QueryExecuted::class]);

        runTool('DELETE FROM customers');

        Event::assertDispatched(QueryRejected::class, function (QueryRejected $e) {
            return $e->sql === 'DELETE FROM customers'
                && str_contains($e->reason, 'Only SELECT queries are allowed')
                && $e->profile->name === 'research';
        });

        Event::assertNotDispatched(QueryExecuted::class);
    });
});

describe('telescope reads', function () {
    beforeEach(function () {
        Schema::create('telescope_entries', function (Blueprint $table) {
            $table->id('sequence');
            $table->uuid('uuid');
            $table->uuid('batch_id');
            $table->string('type');
            $table->text('content');
        });

        DB::table('telescope_entries')->insert([
            'uuid' => 'u1', 'batch_id' => 'b1', 'type' => 'request',
            'content' => json_encode(['method' => 'GET', 'uri' => '/x', 'payload' => ['email' => 'a@b.co']]),
        ]);
    });

    it('records which heavy fields were pulled', function () {
        Event::fake([TelescopeBatchRead::class]);

        $tool = new TelescopeTool(Profile::make('research'));
        app()->call([$tool, 'handle'], [
            'request' => new Request(['batch_id' => 'b1', 'include' => 'payload']),
        ]);

        Event::assertDispatched(TelescopeBatchRead::class, function (TelescopeBatchRead $e) {
            return $e->batchId === 'b1'
                && $e->include === ['payload']
                && $e->includedHeavyFields() === true;
        });
    });

    it('distinguishes a plain digest from a heavy read', function () {
        Event::fake([TelescopeBatchRead::class]);

        $tool = new TelescopeTool(Profile::make('research'));
        app()->call([$tool, 'handle'], ['request' => new Request(['batch_id' => 'b1'])]);

        Event::assertDispatched(TelescopeBatchRead::class,
            fn (TelescopeBatchRead $e) => $e->includedHeavyFields() === false);
    });
});
