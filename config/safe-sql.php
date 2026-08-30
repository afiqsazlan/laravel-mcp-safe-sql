<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Resource URI Scheme
    |--------------------------------------------------------------------------
    |
    | Scheme used for MCP resource URIs exposed by this package, e.g.
    | "safe-sql://database/schema". Set this to something recognisable for
    | your own application.
    |
    */

    'uri_scheme' => env('SAFE_SQL_URI_SCHEME', 'safe-sql'),

    /*
    |--------------------------------------------------------------------------
    | Profiles
    |--------------------------------------------------------------------------
    |
    | A profile bundles three otherwise-independent concerns: which connection
    | to read, whether results are pseudonymized, and which tools are exposed.
    | Each profile is served by its own thin Server subclass and therefore its
    | own endpoint, so access to each can be granted independently.
    |
    | Do not merge profiles into a single endpoint. Two separate endpoints are
    | deliberate: anonymized production and literal non-production data are
    | different sensitivity tiers, and server instructions stay resident in the
    | model's context for the whole session, so a merged server makes every
    | conversation pay for both instruction sets.
    |
    | Available tools: "sql", "schema", "telescope".
    |
    */

    'profiles' => [

        'research' => [
            // Connection name from config/database.php. Null uses the app default.
            // Point this at a read replica whenever one is available.
            'connection' => env('SAFE_SQL_RESEARCH_CONNECTION'),

            // Pseudonymize result values before they leave the process.
            // Defaults to true. Only set this false for a connection whose
            // contents you would be comfortable pasting into a public issue.
            'anonymize' => true,

            'tools' => ['sql', 'schema'],

            // Absolute path to a markdown file of app-specific instructions
            // (schema notes, enums, timezone conventions). Concatenated after
            // the package's own instructions rather than replacing them.
            'instructions' => null,
        ],

        'debug' => [
            'connection' => env('SAFE_SQL_DEBUG_CONNECTION'),

            // Still true, even for a non-production endpoint. Staging is
            // routinely seeded from a production dump, and Telescope is often
            // enabled there precisely because that is where bugs reproduce —
            // so "not production" is not the same as "not real people".
            //
            // Set this false only for a database whose contents you would be
            // comfortable pasting into a public issue.
            'anonymize' => true,

            'tools' => ['sql', 'schema', 'telescope'],
            'instructions' => null,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Guardrails against a single query exhausting the database, the response,
    | or the model's context window.
    |
    */

    'limits' => [
        // LIMIT injected into queries that do not declare one.
        'max_rows' => 500,

        // Rows actually serialized into the MCP response.
        'max_response_rows' => 150,

        // MySQL max_execution_time for the session, in milliseconds.
        'timeout_ms' => 30000,

        // Individual cell values longer than this are truncated.
        'max_cell_length' => 500,

        // Telescope digest caps.
        'max_queries' => 50,
        'max_trace_frames' => 12,
        'max_string' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Anonymizer
    |--------------------------------------------------------------------------
    |
    | Pseudonymization is fail-closed: a value is returned raw only when it is
    | positively established as safe. Anything unrecognised is pseudonymized.
    |
    | This matters because the anonymizer only ever sees result column *labels*,
    | not the source columns behind them. An agent writing
    | "SELECT email AS id FROM users" for readable output would defeat any
    | scheme that trusts labels alone, so labels are never the only signal.
    |
    */

    'anonymizer' => [

        /*
        | Salt lifetime controls whether a given input pseudonymizes to the
        | same token across queries.
        |
        |   "session" — stable within one MCP session (default). Multi-query
        |               analysis can follow one subject; tokens are meaningless
        |               once the session ends.
        |   "request" — fresh salt per tool call. Most private, but the same
        |               person hashes differently in every query, which silently
        |               breaks any cross-query correlation.
        |   "config"  — derived from the secret below. Tokens are stable across
        |               sessions and deploys, enabling long-running analysis at
        |               the cost of being a durable pseudonym.
        */
        'salt' => [
            'lifetime' => env('SAFE_SQL_SALT_LIFETIME', 'session'),
            'secret' => env('SAFE_SQL_SALT_SECRET'),
        ],

        /*
        | Column labels whose values are structurally incapable of identifying
        | a person and may pass through raw. Extended, not replaced, by the app.
        | A label here still does not guarantee raw output: values are also
        | checked against the patterns below.
        */
        'safe_columns' => [
            'id', 'uuid', 'ulid',
            'created_at', 'updated_at', 'deleted_at',
            'count', 'total', 'sum', 'avg', 'min', 'max',
            'status', 'state', 'type', 'kind', 'category',
            'is_active', 'is_enabled', 'active', 'enabled',
        ],

        /*
        | Label suffixes and prefixes that are safe by convention. Without
        | these, every unlisted timestamp or foreign key would be pseudonymized
        | and the output would be unreadable.
        |
        | Note "/_id$/" also matches labels like "national_id". That is fine:
        | the pii_columns map below is consulted first and wins, and value
        | patterns still apply afterwards. Add your own identifier columns to
        | pii_columns rather than relying on this list to exclude them.
        */
        'safe_column_patterns' => [
            '/_at$/',
            '/_date$/',
            '/_id$/',
            '/_count$/',
            '/_total$/',
            '/^is_/',
            '/^has_/',
        ],

        /*
        | Column labels always pseudonymized, with the token type to use. These
        | are generic defaults; your application merges its own additions.
        */
        'pii_columns' => [
            // Direct identifiers
            'name' => 'name',
            'full_name' => 'name',
            'first_name' => 'name',
            'last_name' => 'name',
            'username' => 'name',
            'email' => 'email',
            'email_address' => 'email',
            'phone' => 'phone',
            'phone_no' => 'phone',
            'phone_number' => 'phone',
            'mobile_no' => 'phone',
            'mobile_number' => 'phone',

            // Government and financial identifiers
            'national_id' => 'gov_id',
            'passport_no' => 'gov_id',
            'ssn' => 'gov_id',
            'tax_id' => 'gov_id',
            'bank_account_no' => 'bank',
            'iban' => 'bank',
            'card_number' => 'bank',

            // Location
            'address' => 'address',
            'address_1' => 'address',
            'address_2' => 'address',
            'street' => 'address',
            'postcode' => 'address',
            'postal_code' => 'address',
            'zip' => 'address',
            'latitude' => 'geo',
            'longitude' => 'geo',
            'lat' => 'geo',
            'lng' => 'geo',

            // Sensitive personal data
            'date_of_birth' => 'dob',
            'dob' => 'dob',
            'birth_date' => 'dob',

            // Credentials
            'password' => 'secret',
            'remember_token' => 'secret',
            'api_key' => 'secret',
            'secret' => 'secret',
            'token' => 'secret',
            'access_token' => 'secret',
            'refresh_token' => 'secret',

            // Network
            'ip_address' => 'ip',
            'ip' => 'ip',

            // Media that commonly resolves to a face or a signed document
            'avatar' => 'url',
            'photo' => 'url',
            'image' => 'url',
        ],

        /*
        | Value-shape detection. Applied regardless of column label, so an
        | aliased or computed column cannot smuggle a value past the map above.
        */
        'value_patterns' => [
            'email' => '/[\w.+-]+@[\w-]+\.[\w.-]+/',
            'gov_id' => '/\b\d{6}-\d{2}-\d{4}\b/',
            'bank' => '/\b(?:\d[ -]*?){13,19}\b/',
            'secret' => '/\b(?:eyJ[\w-]+\.[\w-]+\.[\w-]+|sk_(?:live|test)_\w+|gh[pousr]_\w+)\b/',
            'ip' => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
        ],

        /*
        | Free-text columns are the most common accidental PII carrier: notes,
        | remarks and descriptions routinely contain names and phone numbers.
        | String values longer than this are pseudonymized unless their label
        | appears in safe_columns.
        */
        'free_text_threshold' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema Digest
    |--------------------------------------------------------------------------
    */

    'schema' => [
        /*
        | Tables the agent should be told about up front, in full: columns,
        | types, keys and foreign key targets. No default — which tables matter
        | is inherently application-specific.
        |
        | Everything else is listed by name only, for the agent to inspect with
        | describe-table when it needs to. Leaving this empty is therefore the
        | cheap default rather than the expensive one: you get a table index
        | instead of every column in the database.
        */
        'core_tables' => [],

        /*
        | Framework plumbing that carries no domain meaning, plus the OAuth
        | tables, which hold credentials. Extended, not replaced, by the app.
        */
        'excluded_tables' => [
            'migrations',
            'failed_jobs',
            'jobs',
            'job_batches',
            'password_resets',
            'password_reset_tokens',
            'personal_access_tokens',
            'sessions',
            'cache',
            'cache_locks',
            'telescope_entries',
            'telescope_entries_tags',
            'telescope_monitoring',
            'pulse_entries',
            'pulse_aggregates',
            'pulse_values',
            'oauth_access_tokens',
            'oauth_auth_codes',
            'oauth_clients',
            'oauth_personal_access_clients',
            'oauth_refresh_tokens',
        ],

        'cache_ttl' => 6 * 60 * 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Telescope
    |--------------------------------------------------------------------------
    */

    'telescope' => [
        /*
        | Headers stripped before any Telescope entry is returned. Unlike column
        | labels, header names are fixed and cannot be aliased, so a denylist is
        | sound here.
        */
        'header_denylist' => [
            'authorization',
            'proxy-authorization',
            'cookie',
            'set-cookie',
            'x-api-key',
            'x-auth-token',
            'x-csrf-token',
            'x-xsrf-token',
            'php-auth-pw',
            'php-auth-user',
        ],
    ],

];
