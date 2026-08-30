<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Tools;

use Afiqsazlan\SafeSql\Profiles\Profile;
use Afiqsazlan\SafeSql\Sql\QueryExecutorFactory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Config;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[IsIdempotent]
#[IsReadOnly]
class ExecuteSqlTool extends Tool
{
    protected string $name = 'execute-sql';

    public function __construct(protected Profile $profile) {}

    public function description(): string
    {
        if (($custom = $this->profile->describes('sql')) !== null) {
            return $custom;
        }

        return 'Runs against: '.$this->profile->sourceDescription()."\n\n".<<<'MARKDOWN'
        Run a read-only SQL query against the application database.

        Only SELECT, WITH and plain EXPLAIN are accepted. Anything that writes,
        executes, or touches the filesystem is rejected before it reaches the
        database.

        Guidelines:
        - Queries are subject to a server-side timeout. Filter with a WHERE
          clause — dates, status columns, indexed keys — rather than scanning.
        - A LIMIT is added automatically when you do not supply one, and the
          response is capped independently of that.
        - Prefix a query with EXPLAIN to inspect its plan without running it.
          EXPLAIN ANALYZE and EXPLAIN FOR CONNECTION are not allowed, because
          they execute the statement.
        - Start narrow and widen. One focused query per question beats one
          query that tries to answer everything.
        MARKDOWN;
    }

    public function handle(Request $request, QueryExecutorFactory $executors): Response
    {
        $validated = $request->validate([
            'query' => 'required|string',
        ]);

        try {
            $result = $executors
                ->make($this->profile, $request->sessionId())
                ->execute($validated['query']);
        } catch (Throwable $e) {
            return Response::error($e->getMessage());
        }

        $cap = (int) Config::get('safe-sql.limits.max_response_rows', 150);
        $rows = array_slice($result->rows, 0, $cap);

        return Response::json([
            // Stated on every response so a query answered by the wrong server
            // is visible rather than silently plausible.
            'source' => $this->profile->sourceDescription(),
            'profile' => $this->profile->name,
            'rowCount' => $result->rowCount,
            'showing' => count($rows),
            'truncated' => $result->rowCount > count($rows),
            'executionMs' => $result->executionMs,
            'columns' => $result->columns(),
            'rows' => $rows,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The SQL query to run. SELECT, WITH, or EXPLAIN SELECT.')
                ->required(),
        ];
    }
}
