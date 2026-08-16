<?php

declare(strict_types=1);

use Afiqsazlan\SafeSql\Profiles\Profile;
use Afiqsazlan\SafeSql\Tools\TelescopeTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;

beforeEach(function () {
    config()->set('safe-sql.profiles.debugging', [
        'connection' => 'testing',
        'anonymize' => true,
        'tools' => ['telescope'],
    ]);

    Schema::create('telescope_entries', function (Blueprint $table) {
        $table->id('sequence');
        $table->uuid('uuid');
        $table->uuid('batch_id');
        $table->string('type');
        $table->text('content');
    });

    // A registration request: the exact shape §4.3 warns about.
    seedEntry('request', [
        'method' => 'POST',
        'uri' => 'https://app.test/register?ref=alice@example.com',
        'response_status' => 500,
        'duration' => 412,
        'controller_action' => 'RegisterController@store',
        'payload' => [
            'name' => 'Alice Tan',
            'email' => 'alice@example.com',
            'phone' => '0123456789',
            'password' => 'hunter2',
            'national_id' => '900101015533',
            'marketing_opt_in' => true,
        ],
        'headers' => [
            'authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.abc.def',
            'cookie' => 'session=abc123',
            'x-api-key' => 'sk_live_verysecret',
            'content-type' => 'application/json',
            'user-agent' => 'curl/8.0',
        ],
        'response' => ['error' => 'Could not create user alice@example.com'],
    ]);

    seedEntry('exception', [
        'class' => 'RuntimeException',
        'message' => 'Duplicate entry alice@example.com for key users_email_unique',
        'file' => '/app/Http/Controllers/RegisterController.php',
        'line' => 42,
        'trace' => array_map(fn ($i) => ['file' => "/app/frame{$i}.php", 'line' => $i, 'function' => 'handle'], range(1, 30)),
    ]);

    seedEntry('query', [
        'sql' => "select * from users where email = 'alice@example.com'",
        'time' => 12.5,
    ]);

    seedEntry('log', [
        'level' => 'error',
        'message' => 'Registration failed for alice@example.com from 192.168.1.50',
    ]);
});

function seedEntry(string $type, array $content): void
{
    DB::table('telescope_entries')->insert([
        'uuid' => 'uuid-'.$type,
        'batch_id' => 'batch-1',
        'type' => $type,
        'content' => json_encode($content),
    ]);
}

/** @return array<string, mixed> */
function digest(array $arguments = [], string $profile = 'debugging'): array
{
    $tool = new TelescopeTool(Profile::make($profile));

    $response = app()->call([$tool, 'handle'], [
        'request' => new Request($arguments + ['batch_id' => 'batch-1']),
    ]);

    return json_decode((string) $response->content(), true) ?: [];
}

describe('lookup', function () {
    it('resolves a uuid to its whole batch', function () {
        $tool = new TelescopeTool(Profile::make('debugging'));

        $response = app()->call([$tool, 'handle'], [
            'request' => new Request(['uuid' => 'uuid-query']),
        ]);

        $result = json_decode((string) $response->content(), true);

        expect($result['batchId'])->toBe('batch-1')
            ->and($result['entryCount'])->toBe(4);
    });

    it('requires an identifier', function () {
        $tool = new TelescopeTool(Profile::make('debugging'));

        $response = app()->call([$tool, 'handle'], [
            'request' => new Request([]),
        ]);

        expect($response->isError())->toBeTrue()
            ->and((string) $response->content())->toContain('Provide a uuid or a batch_id');
    });

    it('reports an unknown uuid', function () {
        $tool = new TelescopeTool(Profile::make('debugging'));

        $response = app()->call([$tool, 'handle'], [
            'request' => new Request(['uuid' => 'nope']),
        ]);

        expect($response->isError())->toBeTrue();
    });
});

describe('context budget', function () {
    it('omits heavy fields by default', function () {
        $request = digest()['request'];

        expect($request)->not->toHaveKey('payload')
            ->and($request)->not->toHaveKey('headers')
            ->and($request)->not->toHaveKey('response');
    });

    it('keeps the cheap request line', function () {
        expect(digest()['request'])
            ->method->toBe('POST')
            ->status->toBe(500)
            ->durationMs->toBe(412)
            ->controllerAction->toBe('RegisterController@store');
    });

    it('caps stack frames unless the full trace is requested', function () {
        expect(digest()['exceptions'][0]['trace'])->toHaveCount(12)
            ->and(digest()['exceptions'][0]['traceTruncated'])->toBe(30)
            ->and(digest(['include' => 'trace'])['exceptions'][0]['trace'])->toHaveCount(30);
    });

    it('stays small for a whole batch', function () {
        // The competing telescope packages compete on tool count; this one
        // competes on not blowing up the context window.
        expect(strlen((string) json_encode(digest())))->toBeLessThan(3000);
    });
});

/*
 * §4.3: the reference implementation reads telescope_entries.content straight
 * off the connection and returns it, so include=payload,headers,response is a
 * raw PII and credential firehose.
 */
describe('pseudonymization of heavy fields', function () {
    it('pseudonymizes a request payload', function () {
        $payload = digest(['include' => 'payload'])['request']['payload'];

        expect($payload['name'])->toStartWith('[name:')
            ->and($payload['email'])->toStartWith('[email:')
            ->and($payload['phone'])->toStartWith('[phone:')
            ->and($payload['password'])->toStartWith('[secret:')
            ->and($payload['national_id'])->toStartWith('[gov_id:');
    });

    it('leaves harmless payload values readable', function () {
        expect(digest(['include' => 'payload'])['request']['payload']['marketing_opt_in'])->toBeTrue();
    });

    it('pseudonymizes PII inside a response body', function () {
        $response = digest(['include' => 'response'])['request']['response'];

        expect($response['error'])->toContain('[email:')
            ->and($response['error'])->not->toContain('alice@example.com');
    });

    it('never emits the raw address anywhere in the digest', function () {
        $everything = (string) json_encode(digest(['include' => 'payload,headers,response,trace']));

        expect($everything)->not->toContain('alice@example.com')
            ->and($everything)->not->toContain('hunter2')
            ->and($everything)->not->toContain('sk_live_verysecret');
    });
});

describe('header denylist', function () {
    it('removes credential-bearing headers entirely', function () {
        $headers = digest(['include' => 'headers'])['request']['headers'];

        expect($headers['authorization'])->toBe('[removed]')
            ->and($headers['cookie'])->toBe('[removed]')
            ->and($headers['x-api-key'])->toBe('[removed]');
    });

    it('keeps diagnostic headers readable', function () {
        $headers = digest(['include' => 'headers'])['request']['headers'];

        // Header values are protocol metadata, so they are redacted inline
        // rather than tokenized wholesale.
        expect($headers['content-type'])->toBe('application/json')
            ->and($headers['user-agent'])->toBe('curl/8.0');
    });

    it('still redacts PII carried in a header value', function () {
        config()->set('safe-sql.profiles.debugging', [
            'connection' => 'testing', 'anonymize' => true, 'tools' => ['telescope'],
        ]);

        DB::table('telescope_entries')->where('type', 'request')->update([
            'content' => json_encode([
                'method' => 'GET',
                'uri' => '/x',
                'headers' => ['x-forwarded-for' => '203.0.113.9', 'referer' => 'https://x.test/?u=a@b.co'],
            ]),
        ]);

        $headers = digest(['include' => 'headers'])['request']['headers'];

        expect($headers['x-forwarded-for'])->toStartWith('[ip:')
            ->and($headers['referer'])->toContain('[email:')
            ->and($headers['referer'])->toContain('x.test');
    });

    it('never leaks the bearer token', function () {
        expect((string) json_encode(digest(['include' => 'headers'])))
            ->not->toContain('eyJhbGciOiJIUzI1NiJ9');
    });
});

describe('inline redaction of free text', function () {
    it('redacts an exception message without destroying it', function () {
        $message = digest()['exceptions'][0]['message'];

        // Readability is the point: the key name is what makes this debuggable.
        expect($message)->toContain('Duplicate entry')
            ->and($message)->toContain('users_email_unique')
            ->and($message)->toContain('[email:')
            ->and($message)->not->toContain('alice@example.com');
    });

    it('redacts interpolated bindings in logged SQL', function () {
        $sql = digest()['queries']['items'][0]['sql'];

        expect($sql)->toContain('select * from users where email =')
            ->and($sql)->not->toContain('alice@example.com');
    });

    it('redacts a log line', function () {
        $message = digest()['logs'][0]['message'];

        expect($message)->toContain('Registration failed for')
            ->and($message)->not->toContain('alice@example.com')
            ->and($message)->not->toContain('192.168.1.50');
    });

    it('redacts PII from a URI query string', function () {
        expect(digest()['request']['uri'])
            ->toContain('/register')
            ->not->toContain('alice@example.com');
    });
});

describe('profiles that opt out', function () {
    it('returns literal values when anonymize is false', function () {
        config()->set('safe-sql.profiles.qa', [
            'connection' => 'testing', 'anonymize' => false, 'tools' => ['telescope'],
        ]);

        $payload = digest(['include' => 'payload'], 'qa')['request']['payload'];

        expect($payload['email'])->toBe('alice@example.com');
    });

    it('still removes credential headers when anonymize is false', function () {
        config()->set('safe-sql.profiles.qa', [
            'connection' => 'testing', 'anonymize' => false, 'tools' => ['telescope'],
        ]);

        // The denylist is not part of pseudonymization. Credentials are never
        // a debugging aid, whatever the profile's sensitivity tier.
        expect(digest(['include' => 'headers'], 'qa')['request']['headers']['authorization'])
            ->toBe('[removed]');
    });
});
