<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Sql;

class QueryResult
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $rowCount,
        public readonly int $executionMs,
        public readonly bool $truncated,
    ) {}

    /**
     * @return array<int, string>
     */
    public function columns(): array
    {
        return $this->rows === [] ? [] : array_keys($this->rows[0]);
    }
}
