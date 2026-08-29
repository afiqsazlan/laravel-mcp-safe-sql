<?php

declare(strict_types=1);

use Afiqsazlan\SafeSql\Instructions\InstructionComposer;
use Afiqsazlan\SafeSql\Profiles\Profile;

function composed(array $config): string
{
    config()->set('safe-sql.profiles.composed', $config + ['connection' => 'testing']);

    return (new InstructionComposer)->compose(Profile::make('composed'));
}

/**
 * Instructions are wrapped prose, so a phrase may straddle a newline.
 * Collapse whitespace before asserting on wording.
 */
function prose(array $config): string
{
    return (string) preg_replace('/\s+/', ' ', composed($config));
}

describe('always included', function () {
    it('states the read-only guarantee', function () {
        expect(composed([]))->toContain('read-only')
            ->and(composed([]))->toContain('EXPLAIN ANALYZE');
    });

    it('explains that a capped result may be incomplete', function () {
        expect(composed([]))->toContain('truncated');
    });
});

describe('pseudonymization rules', function () {
    it('is included when the profile anonymizes', function () {
        $instructions = composed(['anonymize' => true]);

        expect($instructions)->toContain('Pseudonymized values')
            ->and($instructions)->toContain('[email:51e84a2b]');
    });

    it('warns that tokens cannot be used as filters', function () {
        // Without this an agent writes WHERE email = '[email:…]', gets zero
        // rows, and reports that the person does not exist.
        expect(prose(['anonymize' => true]))
            ->toContain("`WHERE email = '[email:51e84a2b]'` matches nothing");
    });

    it('states that aggregates are still exact', function () {
        expect(composed(['anonymize' => true]))->toContain('Aggregates are exact');
    });

    it('states that tokens do not correlate across sessions', function () {
        expect(composed(['anonymize' => true]))->toContain('cannot compare across sessions');
    });

    it('explains that [value:…] means unclassified, not sensitive', function () {
        expect(prose(['anonymize' => true]))
            ->toContain('this column has not been classified yet')
            ->toContain('not "this is sensitive"');
    });

    it('is omitted when the profile does not anonymize', function () {
        // A profile returning literal values should not pay context for rules
        // about tokens it will never emit.
        expect(composed(['anonymize' => false]))->not->toContain('Pseudonymized values');
    });
});

describe('telescope instructions', function () {
    it('is included only when the tool is exposed', function () {
        expect(composed(['tools' => ['sql', 'telescope']]))->toContain('Telescope')
            ->and(composed(['tools' => ['sql']]))->not->toContain('# Telescope');
    });

    it('describes the digest-then-verify loop', function () {
        expect(prose(['tools' => ['telescope']]))
            ->toContain('Verify what was actually written by querying the database');
    });

    it('tells the agent credential headers are unavailable', function () {
        expect(composed(['tools' => ['telescope']]))->toContain('[removed]');
    });
});

describe('application instructions', function () {
    it('appends rather than replaces the package instructions', function () {
        $path = sys_get_temp_dir().'/safe-sql-app-instructions.md';
        file_put_contents($path, '# Our schema\n\nstatus_id 1 = pending.');

        $instructions = composed(['anonymize' => true, 'instructions' => $path]);

        // The reference implementation replaced the whole block here, which
        // silently dropped the pseudonym rules.
        expect($instructions)->toContain('Our schema')
            ->and($instructions)->toContain('Pseudonymized values')
            ->and($instructions)->toContain('read-only');

        unlink($path);
    });

    it('throws when the configured file is missing', function () {
        expect(fn () => composed(['instructions' => '/no/such/file.md']))
            ->toThrow(RuntimeException::class, 'does not exist');
    });
});

describe('context cost', function () {
    it('stays small for the common profile', function () {
        // Instructions are resident for the whole session, so this is a budget,
        // not a formatting preference.
        expect(strlen(composed(['anonymize' => true, 'tools' => ['sql', 'schema']])))
            ->toBeLessThan(5000);
    });
});
