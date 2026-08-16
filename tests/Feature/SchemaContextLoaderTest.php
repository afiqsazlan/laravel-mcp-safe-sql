<?php

declare(strict_types=1);

use Afiqsazlan\SafeSql\Profiles\Profile;
use Afiqsazlan\SafeSql\Resources\DatabaseSchemaResource;
use Afiqsazlan\SafeSql\Schema\SchemaContextLoader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;

beforeEach(function () {
    config()->set('safe-sql.profiles.testing', [
        'connection' => 'testing',
        'tools' => ['sql', 'schema'],
    ]);

    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->timestamps();
    });

    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->foreignId('customer_id')->constrained('customers');
        $table->string('status');
    });

    Schema::create('migrations', function (Blueprint $table) {
        $table->id();
        $table->string('migration');
    });

    Cache::flush();
});

function loader(): SchemaContextLoader
{
    return app(SchemaContextLoader::class);
}

describe('table selection', function () {
    it('lists tables by name when no core tables are configured', function () {
        config()->set('safe-sql.schema.core_tables', []);

        $digest = loader()->load(Profile::make('testing'));

        // The cheap default: an index, not every column in the database.
        expect($digest)->toContain('TABLES (use describe-table')
            ->and($digest)->toContain('customers, orders')
            ->and($digest)->not->toContain('TABLE: customers');
    });

    it('describes core tables in full', function () {
        config()->set('safe-sql.schema.core_tables', ['customers']);

        $digest = loader()->load(Profile::make('testing'));

        expect($digest)->toContain('TABLE: customers')
            ->and($digest)->toContain('- email')
            ->and($digest)->toContain('- name');
    });

    it('lists non-core tables by name only', function () {
        config()->set('safe-sql.schema.core_tables', ['customers']);

        $digest = loader()->load(Profile::make('testing'));

        expect($digest)->toContain('OTHER TABLES')
            ->and($digest)->toContain('orders')
            ->and($digest)->not->toContain('TABLE: orders');
    });

    it('omits excluded tables entirely', function () {
        config()->set('safe-sql.schema.core_tables', ['customers']);

        expect(loader()->load(Profile::make('testing')))->not->toContain('migrations');
    });
});

describe('column detail', function () {
    beforeEach(fn () => config()->set('safe-sql.schema.core_tables', ['customers', 'orders']));

    it('reports nullability', function () {
        $digest = loader()->load(Profile::make('testing'));

        expect($digest)->toContain('- email')
            ->and($digest)->toMatch('/- name \w+ NOT NULL/');
    });

    it('resolves foreign keys to their target', function () {
        // The reason to spend tokens on core tables at all: an agent can write
        // a correct join without a round trip.
        expect(loader()->load(Profile::make('testing')))
            ->toContain('customer_id')
            ->toContain('-> customers.id');
    });
});

describe('caching', function () {
    it('caches the digest', function () {
        config()->set('safe-sql.schema.core_tables', ['customers']);

        $first = loader()->load(Profile::make('testing'));

        Schema::create('later', fn (Blueprint $table) => $table->id());

        expect(loader()->load(Profile::make('testing')))->toBe($first);
    });

    it('rebuilds after being forgotten', function () {
        config()->set('safe-sql.schema.core_tables', ['customers']);

        loader()->load(Profile::make('testing'));
        Schema::create('later', fn (Blueprint $table) => $table->id());
        loader()->forget(Profile::make('testing'));

        expect(loader()->load(Profile::make('testing')))->toContain('later');
    });

    it('keys the cache per connection, not globally', function () {
        // A single shared key would serve one profile's schema to another.
        config()->set('safe-sql.profiles.other', ['connection' => 'other']);

        $a = (new ReflectionClass(SchemaContextLoader::class))->getMethod('cacheKey');
        $a->setAccessible(true);

        expect($a->invoke(loader(), Profile::make('testing')))
            ->not->toBe($a->invoke(loader(), Profile::make('other')));
    });
});

describe('the schema resource', function () {
    it('uses the configured uri scheme', function () {
        config()->set('safe-sql.uri_scheme', 'acme');

        expect((new DatabaseSchemaResource(Profile::make('testing')))->uri())
            ->toBe('acme://database/schema');
    });

    it('returns the digest', function () {
        config()->set('safe-sql.schema.core_tables', ['customers']);

        $resource = new DatabaseSchemaResource(Profile::make('testing'));

        $response = app()->call([$resource, 'handle'], [
            'request' => new Request([]),
        ]);

        expect((string) $response->content())->toContain('TABLE: customers');
    });
});
