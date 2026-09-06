<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Events;

use Afiqsazlan\SafeSql\Profiles\Profile;

/**
 * A query was refused before it reached the database.
 *
 * Worth auditing separately from successful queries. One rejection is an agent
 * writing clumsy SQL; a burst of them against the same endpoint is worth a
 * human looking at.
 */
class QueryRejected
{
    public function __construct(
        public readonly Profile $profile,
        public readonly string $sql,
        public readonly string $reason,
        public readonly ?string $userId = null,
        public readonly ?string $sessionId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'profile' => $this->profile->name,
            'source' => $this->profile->sourceDescription(),
            'connection' => $this->profile->connection,
            'user_id' => $this->userId,
            'session_id' => $this->sessionId,
            'reason' => $this->reason,
        ];
    }
}
