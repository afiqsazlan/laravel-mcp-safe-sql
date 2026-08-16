<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Tools;

use Afiqsazlan\SafeSql\Anonymization\AnonymizerFactory;
use Afiqsazlan\SafeSql\Contracts\Anonymizer;
use Afiqsazlan\SafeSql\Profiles\Profile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Config;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Projects a whole Telescope batch to a compact digest.
 *
 * The design goal is context budget, not coverage. One tool, entry point is a
 * pasted Telescope URL, and a batch costs a few hundred tokens instead of the
 * 10k+ a full response body runs to. Heavy fields are opt-in via `include`.
 *
 * Everything that can carry user data is routed through the anonymizer before
 * it leaves this class. Telescope stores raw request payloads, response bodies
 * and headers, so with include=payload,headers,response an un-anonymized
 * version of this tool is a PII and credential firehose. Free text is redacted
 * inline rather than replaced wholesale, so exception messages stay debuggable.
 */
#[IsIdempotent]
#[IsReadOnly]
class TelescopeTool extends Tool
{
    protected string $name = 'telescope';

    public function __construct(protected Profile $profile) {}

    public function description(): string
    {
        return $this->profile->describes('telescope') ?? <<<'MARKDOWN'
        Read a Telescope request batch and return a compact digest.

        Paste the uuid from a Telescope URL (…/telescope/requests/<uuid>) and this
        returns the whole batch: the request line with status and duration, any
        exceptions with their top stack frames, the SQL queries that ran, and log
        entries.

        Heavy fields — full response body, request payload, headers, complete stack
        trace — are omitted by default. Pass `include` to pull them in, e.g.
        include="payload,trace". Expect those to be large.

        Values that can carry user data are pseudonymized. Credential-bearing
        headers are removed entirely and cannot be retrieved through this tool.
        MARKDOWN;
    }

    public function handle(
        Request $request,
        DatabaseManager $database,
        AnonymizerFactory $anonymizers,
    ): Response {
        $validated = $request->validate([
            'uuid' => 'nullable|string|max:36',
            'batch_id' => 'nullable|string|max:36',
            'include' => 'nullable|string|max:200',
        ]);

        $include = array_filter(array_map(
            static fn (string $value): string => trim(strtolower($value)),
            explode(',', $validated['include'] ?? '')
        ));

        $connection = $database->connection($this->profile->connection);
        $batchId = $validated['batch_id'] ?? null;

        if (blank($batchId)) {
            if (blank($validated['uuid'] ?? null)) {
                return Response::error('Provide a uuid or a batch_id.');
            }

            $entry = $connection->selectOne(
                'SELECT batch_id FROM telescope_entries WHERE uuid = ? LIMIT 1',
                [$validated['uuid']]
            );

            if ($entry === null) {
                return Response::error("No Telescope entry found for uuid {$validated['uuid']}.");
            }

            $batchId = $entry->batch_id;
        }

        $entries = $connection->select(
            'SELECT type, content FROM telescope_entries WHERE batch_id = ? ORDER BY sequence',
            [$batchId]
        );

        if ($entries === []) {
            return Response::error("No Telescope entries found for batch {$batchId}.");
        }

        $digest = $this->digest(
            (string) $batchId,
            $entries,
            $include,
            $anonymizers->make($this->profile, $request->sessionId()),
        );

        return Response::text((string) json_encode(
            $digest,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    /**
     * @param  array<int, object>  $entries
     * @param  array<int, string>  $include
     * @return array<string, mixed>
     */
    protected function digest(string $batchId, array $entries, array $include, Anonymizer $anonymizer): array
    {
        $request = null;
        $exceptions = [];
        $queries = [];
        $logs = [];
        $other = [];

        foreach ($entries as $entry) {
            $content = json_decode((string) $entry->content, true);
            $content = is_array($content) ? $content : [];

            match ($entry->type) {
                'request' => $request = $this->projectRequest($content, $include, $anonymizer),
                'exception' => $exceptions[] = $this->projectException($content, $include, $anonymizer),
                'query' => $queries[] = $this->projectQuery($content, $anonymizer),
                'log' => $logs[] = $this->projectLog($content, $anonymizer),
                default => $other[] = [
                    'type' => $entry->type,
                    'summary' => $this->summarize($content, $anonymizer),
                ],
            };
        }

        $maxQueries = $this->limit('max_queries', 50);
        $queryCount = count($queries);

        return array_filter([
            'batchId' => $batchId,
            'entryCount' => count($entries),
            'request' => $request,
            'exceptions' => $exceptions ?: null,
            'queries' => $queryCount > 0 ? [
                'count' => $queryCount,
                'truncated' => $queryCount > $maxQueries,
                'items' => array_slice($queries, 0, $maxQueries),
            ] : null,
            'logs' => $logs ?: null,
            'other' => $other ?: null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<int, string>  $include
     * @return array<string, mixed>
     */
    protected function projectRequest(array $content, array $include, Anonymizer $anonymizer): array
    {
        $out = [
            'method' => $content['method'] ?? null,
            // A URI can carry PII in its query string.
            'uri' => isset($content['uri'])
                ? $anonymizer->redactText((string) $content['uri'])
                : null,
            'status' => $content['response_status'] ?? null,
            'durationMs' => $content['duration'] ?? null,
            'memoryMb' => $content['memory'] ?? null,
            'controllerAction' => $content['controller_action'] ?? null,
        ];

        if (in_array('payload', $include, true) && filled($content['payload'] ?? null)) {
            $out['payload'] = $this->anonymizeStructure($content['payload'], $anonymizer);
        }

        if (in_array('headers', $include, true)) {
            $out['headers'] = $this->projectHeaders($content['headers'] ?? [], $anonymizer);
            $out['responseHeaders'] = $this->projectHeaders($content['response_headers'] ?? [], $anonymizer);
        }

        if (in_array('response', $include, true) && isset($content['response'])) {
            $out['response'] = $this->anonymizeStructure($content['response'], $anonymizer);
        }

        return array_filter($out, static fn ($value) => $value !== null);
    }

    /**
     * Remove credential-bearing headers outright.
     *
     * A denylist is sound here, unlike for result columns: header names are
     * fixed by the protocol and cannot be aliased into something else.
     *
     * @return array<string, mixed>
     */
    protected function projectHeaders(mixed $headers, Anonymizer $anonymizer): array
    {
        if (! is_array($headers)) {
            return [];
        }

        /** @var array<int, string> $denied */
        $denied = Config::get('safe-sql.telescope.header_denylist', []);
        $denied = array_map(strtolower(...), $denied);

        $out = [];

        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string) $name), $denied, true)) {
                $out[$name] = '[removed]';

                continue;
            }

            // Redacted inline rather than tokenized. Header values are protocol
            // metadata — "application/json" is not user data, and replacing it
            // wholesale would cost readability for no privacy gain. Inline
            // redaction still catches an address in Referer or an IP in
            // X-Forwarded-For.
            $out[$name] = $this->redactStructure($value, $anonymizer);
        }

        return $out;
    }

    protected function redactStructure(mixed $value, Anonymizer $anonymizer): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->redactStructure($item, $anonymizer), $value);
        }

        return is_string($value)
            ? $this->clipString($anonymizer->redactText($value))
            : $value;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<int, string>  $include
     * @return array<string, mixed>
     */
    protected function projectException(array $content, array $include, Anonymizer $anonymizer): array
    {
        $trace = is_array($content['trace'] ?? null) ? $content['trace'] : [];
        $full = in_array('trace', $include, true);
        $maxFrames = $this->limit('max_trace_frames', 12);
        $frames = $full ? $trace : array_slice($trace, 0, $maxFrames);

        return array_filter([
            'class' => $content['class'] ?? null,
            // Exception messages routinely interpolate user data.
            'message' => isset($content['message'])
                ? $this->clipString($anonymizer->redactText((string) $content['message']))
                : null,
            'location' => isset($content['file'])
                ? $content['file'].':'.($content['line'] ?? '?')
                : null,
            'trace' => array_map(static fn ($frame) => ($frame['file'] ?? '?').':'.($frame['line'] ?? '?')
                .(isset($frame['function']) ? ' '.$frame['function'].'()' : ''), $frames),
            'traceTruncated' => (! $full && count($trace) > $maxFrames) ? count($trace) : null,
        ], static fn ($value) => $value !== null && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    protected function projectQuery(array $content, Anonymizer $anonymizer): array
    {
        return array_filter([
            // Telescope interpolates bindings into the logged SQL, so a WHERE
            // clause here can contain the value it searched for.
            'sql' => isset($content['sql'])
                ? $this->clipString($anonymizer->redactText((string) $content['sql']))
                : null,
            'timeMs' => $content['time'] ?? null,
            'slow' => ! empty($content['slow']) ? true : null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    protected function projectLog(array $content, Anonymizer $anonymizer): array
    {
        return array_filter([
            'level' => $content['level'] ?? null,
            'message' => isset($content['message'])
                ? $this->clipString($anonymizer->redactText((string) $content['message']))
                : null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * Recurse a payload or response, treating array keys as column labels.
     *
     * A request payload is shaped like a result row — "email" => "a@b.co" — so
     * the same label-plus-value rules apply, including the fail-closed default.
     */
    protected function anonymizeStructure(mixed $value, Anonymizer $anonymizer, string $label = ''): mixed
    {
        if (is_array($value)) {
            $out = [];

            foreach ($value as $key => $item) {
                $out[$key] = $this->anonymizeStructure(
                    $item,
                    $anonymizer,
                    is_string($key) ? $key : $label,
                );
            }

            return $out;
        }

        if (is_string($value) && mb_strlen($value) > $this->limit('max_string', 1000)) {
            // Bodies and blobs: redact inline, then clip. Tokenizing the whole
            // thing would throw away the structure that makes it worth reading.
            return $this->clipString($anonymizer->redactText($value));
        }

        return $anonymizer->anonymizeValue($label, $value);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    protected function summarize(array $content, Anonymizer $anonymizer): string
    {
        foreach (['message', 'name', 'action', 'uri', 'sql'] as $key) {
            if (filled($content[$key] ?? null) && is_string($content[$key])) {
                return $this->clipString($anonymizer->redactText($content[$key]));
            }
        }

        return '';
    }

    protected function clipString(string $value): string
    {
        $max = $this->limit('max_string', 1000);

        return mb_strlen($value) > $max
            ? mb_substr($value, 0, $max).'…[truncated]'
            : $value;
    }

    protected function limit(string $key, int $default): int
    {
        return (int) Config::get("safe-sql.limits.{$key}", $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()
                ->description('Telescope entry uuid, e.g. from …/telescope/requests/<uuid>. Resolves to its whole batch.'),
            'batch_id' => $schema->string()
                ->description('Telescope batch id. Use instead of uuid if you already have it.'),
            'include' => $schema->string()
                ->description('Comma-separated heavy fields to include: response, payload, headers, trace. Omit for a compact digest.'),
        ];
    }
}
