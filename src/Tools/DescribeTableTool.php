<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Tools;

use Afiqsazlan\SafeSql\Profiles\Profile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Config;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Lists tables, or describes one table's columns.
 *
 * Output deliberately does not pass through the anonymizer. This tool returns
 * schema metadata — column names, types, keys — and the anonymizer's job is to
 * pseudonymize unrecognised *values*. Routing a column list through it would
 * tokenize the very identifiers the caller asked for.
 *
 * Uses Laravel's schema builder rather than SHOW COLUMNS / information_schema.
 * The table name is then never interpolated into SQL, and the tool works on
 * any driver the framework supports.
 */
#[IsIdempotent]
#[IsReadOnly]
class DescribeTableTool extends Tool
{
    protected string $name = 'describe-table';

    public function __construct(protected Profile $profile) {}

    public function description(): string
    {
        return $this->profile->describes('schema')
            ?? 'List the tables in the database, or describe one table\'s columns, types and keys.';
    }

    public function handle(Request $request, DatabaseManager $database): Response
    {
        $validated = $request->validate([
            'table' => 'nullable|string|max:100',
        ]);

        $schema = $database->connection($this->profile->connection)->getSchemaBuilder();
        $table = $validated['table'] ?? null;

        if (blank($table)) {
            return Response::text($this->listTables($schema));
        }

        // Validated, not rewritten. Silently stripping stray characters would
        // answer a question the caller did not ask; a dotted name also makes
        // the schema builder throw rather than return false.
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return Response::error(
                "[{$table}] is not a valid table name. Use letters, digits and underscores only."
            );
        }

        if (! $schema->hasTable($table)) {
            return Response::error("There is no table named [{$table}].");
        }

        return Response::text($this->describeTable($schema, $table));
    }

    protected function listTables(Builder $schema): string
    {
        /** @var array<int, string> $excluded */
        $excluded = Config::get('safe-sql.schema.excluded_tables', []);

        $lines = [];

        foreach ($schema->getTables() as $table) {
            $name = $table['name'] ?? null;

            if (! is_string($name) || in_array($name, $excluded, true)) {
                continue;
            }

            $size = $table['size'] ?? null;

            $lines[] = is_numeric($size) && $size > 0
                ? sprintf('%s (~%s)', $name, $this->humanSize((int) $size))
                : $name;
        }

        sort($lines);

        return implode("\n", $lines);
    }

    protected function describeTable(Builder $schema, string $table): string
    {
        $lines = [];

        foreach ($schema->getColumns($table) as $column) {
            $line = sprintf(
                '%s %s%s',
                $column['name'],
                $column['type'] ?? $column['type_name'] ?? 'unknown',
                ($column['nullable'] ?? false) ? ' NULL' : ' NOT NULL',
            );

            if (($column['auto_increment'] ?? false) === true) {
                $line .= ' [PK]';
            }

            if (filled($column['comment'] ?? null)) {
                $line .= ' -- '.$column['comment'];
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    protected function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return $bytes.$unit;
            }

            $bytes = intdiv($bytes, 1024);
        }

        return $bytes.'TB';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()
                ->description('Table to describe. Omit to list all tables.'),
        ];
    }
}
