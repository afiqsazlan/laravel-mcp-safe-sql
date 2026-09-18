<?php

declare(strict_types=1);

use Afiqsazlan\SafeSql\Profiles\Profile;
use Afiqsazlan\SafeSql\Tools\ExecuteSqlTool;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;

/*
 * The property §4.2 of the build plan is about, tested through the real tool
 * rather than the salt provider alone: can an analyst follow one subject across
 * separate queries? Under laravel/mcp 1.0 there is no MCP session, so this
 * rests entirely on the authenticated user and the time window.
 */

beforeEach(function () {
    config()->set('safe-sql.profiles.research', [
        'label' => 'production', 'connection' => 'testing',
        'anonymize' => true, 'tools' => ['sql'],
    ]);

    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('email');
    });

    DB::table('customers')->insert(['email' => 'alice@example.com']);
});

function tokenSeenBy(?string $userId): string
{
    auth()->setUser(new GenericUser(['id' => $userId]));

    $tool = new ExecuteSqlTool(Profile::make('research'));
    $response = app()->call([$tool, 'handle'], [
        'request' => new Request(['query' => 'SELECT email FROM customers']),
    ]);

    return json_decode((string) $response->content(), true)['rows'][0]['email'];
}

it('gives one analyst the same token across separate tool calls', function () {
    // Two calls are two HTTP requests with no shared session under 1.0.
    expect(tokenSeenBy('analyst-a'))->toBe(tokenSeenBy('analyst-a'));
});

it('gives two analysts different tokens for the same person', function () {
    expect(tokenSeenBy('analyst-a'))->not->toBe(tokenSeenBy('analyst-b'));
});

it('keeps an analyst\'s tokens stable through the day', function () {
    $this->travelTo('2026-09-18 00:30:00');
    $morning = tokenSeenBy('analyst-a');

    $this->travelTo('2026-09-18 23:30:00');

    expect(tokenSeenBy('analyst-a'))->toBe($morning);
});

it('rotates an analyst\'s tokens when the day changes', function () {
    $this->travelTo('2026-09-18 23:00:00');
    $today = tokenSeenBy('analyst-a');

    $this->travelTo('2026-09-19 01:00:00');

    expect(tokenSeenBy('analyst-a'))->not->toBe($today);
});
