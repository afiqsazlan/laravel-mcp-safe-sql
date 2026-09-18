<?php

declare(strict_types=1);

use Afiqsazlan\SafeSql\Anonymization\SaltProvider;

beforeEach(fn () => SaltProvider::flushProcessSalt());

function salt(string $lifetime, ?string $user = 'user-1', string $when = '2026-09-18 10:00:00', string $window = 'day', bool $stdio = false, ?string $secret = 'secret'): string
{
    return (new SaltProvider($lifetime, $secret, $user, $window, $stdio, new DateTimeImmutable($when, new DateTimeZone('UTC'))))->salt();
}

describe('user lifetime', function () {
    it('is stable for one user within one day', function () {
        expect(salt('user', when: '2026-09-18 00:05:00'))
            ->toBe(salt('user', when: '2026-09-18 23:55:00'));
    });

    it('rotates when the day changes', function () {
        expect(salt('user', when: '2026-09-18 23:59:59'))
            ->not->toBe(salt('user', when: '2026-09-19 00:00:00'));
    });

    it('differs between users, so two analysts cannot pool pseudonyms', function () {
        expect(salt('user', user: 'analyst-a'))->not->toBe(salt('user', user: 'analyst-b'));
    });

    it('buckets in UTC, so the boundary does not move with the server timezone', function () {
        // 07:30 in Kuala Lumpur on the 19th is still the 18th in UTC.
        $klMorning = new SaltProvider('user', 'secret', 'user-1', 'day', false,
            new DateTimeImmutable('2026-09-19 07:30:00', new DateTimeZone('Asia/Kuala_Lumpur')));

        expect($klMorning->salt())->toBe(salt('user', when: '2026-09-18 12:00:00'));
    });

    it('honours a configured window', function (string $window, string $a, string $b, bool $same) {
        $result = salt('user', when: $a, window: $window) === salt('user', when: $b, window: $window);

        expect($result)->toBe($same);
    })->with([
        'same hour' => ['hour', '2026-09-18 10:01', '2026-09-18 10:59', true],
        'next hour' => ['hour', '2026-09-18 10:59', '2026-09-18 11:00', false],
        'same week' => ['week', '2026-09-14 00:00', '2026-09-20 23:00', true],
        'same month' => ['month', '2026-09-01 00:00', '2026-09-30 23:00', true],
    ]);

    it('rejects an unknown window', function () {
        expect(fn () => salt('user', window: 'fortnight'))
            ->toThrow(InvalidArgumentException::class, 'Unknown salt window');
    });

    it('treats the pre-1.0 "session" lifetime as "user"', function () {
        expect(salt('session'))->toBe(salt('user'));
    });
});

describe('without an authenticated user', function () {
    it('is stable for the life of a stdio process, which is one connection', function () {
        expect(salt('user', user: null, stdio: true))->toBe(salt('user', user: null, stdio: true));
    });

    it('falls back to per-call over HTTP, where a worker serves many callers', function () {
        // A process salt on a long-lived HTTP worker would correlate unrelated
        // anonymous callers. Losing correlation is the safe direction to fail.
        expect(salt('user', user: null, stdio: false))->not->toBe(salt('user', user: null, stdio: false));
    });
});

describe('config lifetime', function () {
    it('is stable across users and days', function () {
        expect(salt('config', user: 'a', when: '2026-01-01'))
            ->toBe(salt('config', user: 'b', when: '2026-12-31'));
    });

    it('refuses to run without a secret rather than silently using a weak one', function () {
        expect(fn () => salt('config', secret: null))
            ->toThrow(InvalidArgumentException::class, 'no secret is configured');
    });
});

describe('request lifetime', function () {
    it('differs per instance', function () {
        expect(salt('request'))->not->toBe(salt('request'));
    });
});

describe('general behaviour', function () {
    it('memoizes, so repeated calls on one instance agree', function () {
        $provider = new SaltProvider('request');

        expect($provider->salt())->toBe($provider->salt());
    });

    it('rejects an unknown lifetime', function () {
        expect(fn () => salt('forever'))->toThrow(InvalidArgumentException::class, 'Unknown salt lifetime');
    });
});
