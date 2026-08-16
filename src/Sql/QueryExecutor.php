<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Sql;

use Afiqsazlan\SafeSql\Contracts\Anonymizer;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Runs a validated, row-limited, time-bounded read-only query and hands the
 * result to the anonymizer before it leaves the process.
 *
 * The connection is injected rather than named internally, which is what makes
 * the same executor usable for a production replica and a staging database
 * under different profiles.
 */
class QueryExecutor
{
    /**
     * @param  array<string, int>  $limits
     */
    public function __construct(
        protected ConnectionInterface $connection,
        protected QueryValidator $validator,
        protected Anonymizer $anonymizer,
        protected array $limits,
    ) {}

    public function execute(string $sql): QueryResult
    {
        $this->validator->validate($sql);

        $sql = $this->applyRowLimit($sql);

        $this->applyTimeout();

        $startedAt = microtime(true);
        $rows = $this->connection->select($sql);
        $executionMs = (int) round((microtime(true) - $startedAt) * 1000);

        $rows = array_map(static fn ($row) => (array) $row, $rows);
        $rowCount = count($rows);

        $rows = array_map($this->truncateCells(...), $rows);
        $rows = $this->anonymizer->anonymizeRows($rows);

        return new QueryResult(
            rows: $rows,
            rowCount: $rowCount,
            executionMs: $executionMs,
            truncated: $rowCount >= $this->limit('max_rows'),
        );
    }

    /**
     * Append a LIMIT when the statement does not already declare one at the
     * top level.
     *
     * Parenthesised groups are collapsed first so that a LIMIT belonging to a
     * subquery does not count as bounding the outer statement. String literals
     * are stripped too, so that a value like 'LIMIT' cannot suppress the
     * injection and leave the query unbounded.
     */
    protected function applyRowLimit(string $sql): string
    {
        $sql = SqlSanitizer::normalize($sql);

        // An injected LIMIT would change the plan EXPLAIN reports, and is
        // meaningless for DESCRIBE / SHOW COLUMNS.
        if ($this->validator->isPlanOrMetadata($sql)) {
            return $sql;
        }

        $inspectable = SqlSanitizer::collapseParentheses(
            SqlSanitizer::stripStringLiterals(
                SqlSanitizer::stripComments($sql)
            )
        );

        if (preg_match('/\bLIMIT\b/i', $inspectable) === 1) {
            return $sql;
        }

        // Appended on its own line, not after a space. A statement ending in a
        // "-- ..." or "# ..." comment would otherwise swallow the clause and
        // run unbounded — the precise outcome this cap exists to prevent.
        return $sql."\nLIMIT ".$this->limit('max_rows');
    }

    protected function applyTimeout(): void
    {
        try {
            $this->connection->statement(
                'SET SESSION max_execution_time = '.$this->limit('timeout_ms')
            );
        } catch (Throwable) {
            // max_execution_time requires MySQL 5.7.8+. On anything older, or
            // on MariaDB, the query simply runs without a server-side cap.
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function truncateCells(array $row): array
    {
        $max = $this->limit('max_cell_length');

        return array_map(static function ($value) use ($max) {
            if (is_string($value) && mb_strlen($value) > $max) {
                return mb_substr($value, 0, $max).'...';
            }

            return $value;
        }, $row);
    }

    protected function limit(string $key): int
    {
        return $this->limits[$key];
    }
}
