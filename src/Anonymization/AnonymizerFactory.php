<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Anonymization;

use Afiqsazlan\SafeSql\Contracts\Anonymizer;
use Afiqsazlan\SafeSql\Profiles\Profile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;

class AnonymizerFactory
{
    /**
     * Build the anonymizer for one tool call.
     *
     * The user id is threaded through from the MCP request rather than read
     * from somewhere ambient, because with stateless transport it is what makes
     * tokens stable across one analyst's queries.
     */
    public function make(Profile $profile, ?string $userId = null): Anonymizer
    {
        if (! $profile->anonymize) {
            return new NullAnonymizer;
        }

        /** @var array<string, mixed> $config */
        $config = Config::get('safe-sql.anonymizer', []);

        /** @var array<string, mixed> $salt */
        $salt = $config['salt'] ?? [];

        $provider = new SaltProvider(
            lifetime: (string) ($salt['lifetime'] ?? SaltProvider::LIFETIME_USER),
            // Falls back to the application key so that user-scoped salts are
            // keyed to something secret without extra configuration.
            secret: $salt['secret'] ?? Config::get('app.key'),
            userId: $userId,
            window: (string) ($salt['window'] ?? 'day'),
            // stdio runs one long-lived console process per connection, so
            // there the process is the session. HTTP workers serve many
            // callers and must never share an anonymous salt.
            processIsSession: app()->runningInConsole(),
            // Laravel's clock rather than PHP's, so the window boundary moves
            // with travelTo() in tests and with any app-level clock override.
            now: Date::now(),
        );

        return new PiiAnonymizer(
            salt: $provider->salt(),
            piiColumns: $config['pii_columns'] ?? [],
            safeColumns: $config['safe_columns'] ?? [],
            safeColumnPatterns: $config['safe_column_patterns'] ?? [],
            valuePatterns: $config['value_patterns'] ?? [],
            freeTextThreshold: (int) ($config['free_text_threshold'] ?? 120),
        );
    }
}
