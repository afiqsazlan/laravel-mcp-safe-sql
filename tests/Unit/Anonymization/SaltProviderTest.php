<?php

declare(strict_types=1);

use Afiqsazlan\SafeSql\Anonymization\SaltProvider;

describe('session lifetime', function () {
    it('derives the same salt for the same session', function () {
        $a = new SaltProvider('session', 'secret', 'session-abc');
        $b = new SaltProvider('session', 'secret', 'session-abc');

        expect($a->salt())->toBe($b->salt());
    });

    it('derives different salts for different sessions', function () {
        $a = new SaltProvider('session', 'secret', 'session-abc');
        $b = new SaltProvider('session', 'secret', 'session-xyz');

        expect($a->salt())->not->toBe($b->salt());
    });

    it('falls back to a random salt when there is no session to bind to', function () {
        $a = new SaltProvider('session', 'secret', null);
        $b = new SaltProvider('session', 'secret', null);

        // Losing correlation is the safe direction to fail; emitting a
        // constant salt would leak a stable pseudonym instead.
        expect($a->salt())->not->toBe($b->salt());
    });
});

describe('config lifetime', function () {
    it('derives a stable salt from the secret', function () {
        $a = new SaltProvider('config', 'shared-secret');
        $b = new SaltProvider('config', 'shared-secret');

        expect($a->salt())->toBe($b->salt());
    });

    it('ignores the session, so tokens correlate across sessions', function () {
        $a = new SaltProvider('config', 'shared-secret', 'session-abc');
        $b = new SaltProvider('config', 'shared-secret', 'session-xyz');

        expect($a->salt())->toBe($b->salt());
    });

    it('refuses to run without a secret rather than silently using a weak one', function () {
        expect(fn () => (new SaltProvider('config', null))->salt())
            ->toThrow(InvalidArgumentException::class, 'no secret is configured');
    });
});

describe('request lifetime', function () {
    it('derives a different salt per instance', function () {
        $a = new SaltProvider('request', 'secret', 'session-abc');
        $b = new SaltProvider('request', 'secret', 'session-abc');

        expect($a->salt())->not->toBe($b->salt());
    });
});

describe('general behaviour', function () {
    it('memoizes, so repeated calls agree', function () {
        $provider = new SaltProvider('request');

        expect($provider->salt())->toBe($provider->salt());
    });

    it('rejects an unknown lifetime', function () {
        expect(fn () => (new SaltProvider('forever'))->salt())
            ->toThrow(InvalidArgumentException::class, 'Unknown salt lifetime');
    });
});
