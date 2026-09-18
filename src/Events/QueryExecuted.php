<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Events;

use Afiqsazlan\SafeSql\Anonymization\AnonymizerFactory;
use Afiqsazlan\SafeSql\Profiles\Profile;

/**
 * A read-only query ran successfully.
 *
 * Deliberately carries no result rows. An audit trail that recorded what came
 * back would write pseudonymized-for-a-reason data into logs, which are
 * typically retained longer and read more widely than the MCP session was.
 * Who, when, against what, and how much came back is the audit question;
 * the values themselves are not.
 *
 * The submitted SQL is included in full. It can contain values in a WHERE
 * clause, but those were supplied by the person asking rather than extracted
 * from the database, and an audit record that omits the query answers nothing.
 * If your logs are broadly readable, use redactedSql().
 */
class QueryExecuted
{
    public function __construct(
        public readonly Profile $profile,
        public readonly string $sql,
        public readonly int $rowCount,
        public readonly int $executionMs,
        public readonly bool $truncated,
        public readonly ?string $userId = null,
    ) {}

    /**
     * The query with recognisable PII replaced, for logs you would rather
     * keep free of values entirely.
     */
    public function redactedSql(): string
    {
        return app(AnonymizerFactory::class)
            ->make($this->profile, $this->userId)
            ->redactText($this->sql);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'profile' => $this->profile->name,
            'source' => $this->profile->sourceDescription(),
            'connection' => $this->profile->connection,
            'anonymized' => $this->profile->anonymize,
            'user_id' => $this->userId,
            'row_count' => $this->rowCount,
            'execution_ms' => $this->executionMs,
            'truncated' => $this->truncated,
        ];
    }
}
