<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Schema;

use Afiqsazlan\SafeSql\Profiles\Profile;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * Builds a cached, human-readable digest of the database schema.
 *
 * Core tables are described in full — columns, types, nullability, keys and
 * foreign key targets — so an agent can write a correct join without a round
 * trip. Everything else is listed by name for it to inspect on demand.
 *
 * Uses Laravel's schema builder rather than SHOW TABLES and information_schema,
 * so the loader is driver-agnostic and testable without a MySQL service.
 */
class SchemaContextLoader
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    public function load(Profile $profile): string
    {
        $ttl = (int) Config::get('safe-sql.schema.cache_ttl', 21600);

        return Cache::remember(
            $this->cacheKey($profile),
            $ttl,
            fn (): string => $this->build($profile),
        );
    }

    public function forget(Profile $profile): void
    {
        Cache::forget($this->cacheKey($profile));
    }

    /**
     * Keyed by connection, not globally.
     *
     * Profiles routinely point at different databases, and a single shared key
     * would serve one profile's schema to another.
     */
    protected function cacheKey(Profile $profile): string
    {
        return 'safe-sql:schema:'.($profile->connection ?? 'default');
    }

    protected function build(Profile $profile): string
    {
        $schema = $this->database->connection($profile->connection)->getSchemaBuilder();

        /** @var array<int, string> $excluded */
        $excluded = Config::get('safe-sql.schema.excluded_tables', []);
        /** @var array<int, string> $core */
        $core = Config::get('safe-sql.schema.core_tables', []);

        $tables = [];

        foreach ($schema->getTables() as $table) {
            $name = $table['name'] ?? null;

            if (is_string($name) && ! in_array($name, $excluded, true)) {
                $tables[] = $name;
            }
        }

        sort($tables);

        $coreTables = array_values(array_intersect($tables, $core));
        $otherTables = array_values(array_diff($tables, $coreTables));

        $lines = [];

        foreach ($coreTables as $table) {
            $lines[] = "TABLE: {$table}";

            foreach ($this->describeColumns($schema, $table) as $line) {
                $lines[] = '  - '.$line;
            }

            $lines[] = '';
        }

        if ($otherTables !== []) {
            $lines[] = $coreTables === []
                ? 'TABLES (use describe-table to see columns):'
                : 'OTHER TABLES (use describe-table to see columns):';
            $lines[] = implode(', ', $otherTables);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, string>
     */
    protected function describeColumns(Builder $schema, string $table): array
    {
        $foreignKeys = $this->foreignKeysFor($schema, $table);
        $lines = [];

        foreach ($schema->getColumns($table) as $column) {
            $name = (string) $column['name'];

            $line = $name.' '.($column['type'] ?? $column['type_name'] ?? 'unknown');
            $line .= ($column['nullable'] ?? false) ? ' NULL' : ' NOT NULL';

            if (($column['auto_increment'] ?? false) === true) {
                $line .= ' [PK]';
            }

            if (isset($foreignKeys[$name])) {
                $line .= ' -> '.$foreignKeys[$name];
            }

            if (filled($column['comment'] ?? null)) {
                $line .= ' -- '.$column['comment'];
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return array<string, string>
     */
    protected function foreignKeysFor(Builder $schema, string $table): array
    {
        $map = [];

        foreach ($schema->getForeignKeys($table) as $key) {
            $columns = $key['columns'] ?? [];
            $foreign = $key['foreign_columns'] ?? [];
            $foreignTable = $key['foreign_table'] ?? null;

            if (! is_string($foreignTable)) {
                continue;
            }

            foreach ($columns as $index => $column) {
                $map[(string) $column] = $foreignTable.'.'.($foreign[$index] ?? 'id');
            }
        }

        return $map;
    }
}
