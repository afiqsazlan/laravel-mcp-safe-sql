<?php

declare(strict_types=1);
use Afiqsazlan\SafeSql\SafeSqlServiceProvider;

it('merges the package configuration', function () {
    expect(config('safe-sql'))->toBeArray()
        ->and(config('safe-sql.uri_scheme'))->toBe('safe-sql');
});

it('ships a research profile that anonymizes by default', function () {
    expect(config('safe-sql.profiles.research.anonymize'))->toBeTrue();
});

it('defaults the salt lifetime to per-session', function () {
    expect(config('safe-sql.anonymizer.salt.lifetime'))->toBe('session');
});

it('excludes framework and oauth tables from the schema digest', function () {
    expect(config('safe-sql.schema.excluded_tables'))
        ->toContain('migrations', 'telescope_entries', 'oauth_access_tokens');
});

it('ships no core tables, since they are inherently app-specific', function () {
    expect(config('safe-sql.schema.core_tables'))->toBe([]);
});

it('denies credential-bearing headers in telescope output', function () {
    expect(config('safe-sql.telescope.header_denylist'))
        ->toContain('authorization', 'cookie', 'x-api-key');
});

it('publishes its config under a tag', function () {
    expect(array_keys(
        SafeSqlServiceProvider::pathsToPublish(
            SafeSqlServiceProvider::class,
            'safe-sql-config'
        )
    ))->toHaveCount(1);
});
