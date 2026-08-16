<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Anonymization;

use Afiqsazlan\SafeSql\Contracts\Anonymizer;
use Afiqsazlan\SafeSql\Profiles\Profile;
use Illuminate\Support\Facades\Config;

class AnonymizerFactory
{
    /**
     * Build the anonymizer for one tool call.
     *
     * The session id is threaded through from the MCP request rather than
     * being read from somewhere ambient, because it is what makes tokens
     * stable across the queries of a single conversation.
     */
    public function make(Profile $profile, ?string $sessionId = null): Anonymizer
    {
        if (! $profile->anonymize) {
            return new NullAnonymizer;
        }

        /** @var array<string, mixed> $config */
        $config = Config::get('safe-sql.anonymizer', []);

        /** @var array<string, mixed> $salt */
        $salt = $config['salt'] ?? [];

        $provider = new SaltProvider(
            lifetime: (string) ($salt['lifetime'] ?? SaltProvider::LIFETIME_SESSION),
            // Falls back to the application key so that session-scoped salts
            // are keyed to something secret without extra configuration.
            secret: $salt['secret'] ?? Config::get('app.key'),
            sessionId: $sessionId,
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
