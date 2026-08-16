<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Exceptions;

use InvalidArgumentException;

class UnsafeQueryException extends InvalidArgumentException
{
    public static function notReadOnly(): self
    {
        return new self('Only SELECT queries are allowed.');
    }

    public static function multiStatement(): self
    {
        return new self('Multi-statement queries are not allowed.');
    }

    public static function executableComment(): self
    {
        return new self(
            'MySQL executable comments (/*! ... */) are not allowed, because their contents '.
            'execute despite looking like a comment.'
        );
    }

    public static function explainExecutes(): self
    {
        return new self(
            'EXPLAIN ANALYZE and EXPLAIN FOR CONNECTION are not allowed — use plain EXPLAIN.'
        );
    }
}
