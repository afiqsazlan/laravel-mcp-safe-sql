<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Schema;

class ColumnSuggestion
{
    public const BUCKET_PII = 'pii';

    public const BUCKET_SAFE = 'safe';

    public const BUCKET_REVIEW = 'review';

    public function __construct(
        public readonly string $column,
        public readonly string $bucket,
        public readonly ?string $tokenType,
        public readonly string $reason,
        public readonly bool $fromSampledData = false,
    ) {}

    public static function pii(string $column, string $type, string $reason, bool $sampled = false): self
    {
        return new self($column, self::BUCKET_PII, $type, $reason, $sampled);
    }

    public static function safe(string $column, string $reason): self
    {
        return new self($column, self::BUCKET_SAFE, null, $reason);
    }

    public static function review(string $column, string $reason): self
    {
        return new self($column, self::BUCKET_REVIEW, null, $reason);
    }
}
