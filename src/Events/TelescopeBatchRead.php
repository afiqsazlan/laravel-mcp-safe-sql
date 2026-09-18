<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Events;

use Afiqsazlan\SafeSql\Profiles\Profile;

/**
 * A Telescope batch was read.
 *
 * Audited alongside SQL because it is data access too, and the `include` list
 * is the interesting part: a bare digest is metadata, while
 * include=payload,response returns request bodies. Recording which was asked
 * for is what makes the trail worth keeping.
 */
class TelescopeBatchRead
{
    /**
     * @param  array<int, string>  $include
     */
    public function __construct(
        public readonly Profile $profile,
        public readonly string $batchId,
        public readonly array $include,
        public readonly int $entryCount,
        public readonly ?string $userId = null,
    ) {}

    /**
     * Whether this read pulled request payloads, response bodies or headers
     * rather than just the digest.
     */
    public function includedHeavyFields(): bool
    {
        return array_intersect($this->include, ['payload', 'response', 'headers']) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'profile' => $this->profile->name,
            'source' => $this->profile->sourceDescription(),
            'batch_id' => $this->batchId,
            'include' => $this->include,
            'heavy_fields' => $this->includedHeavyFields(),
            'entry_count' => $this->entryCount,
            'user_id' => $this->userId,
        ];
    }
}
