<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Anonymization;

use InvalidArgumentException;

/**
 * Decides how long a pseudonym stays stable.
 *
 * This is a correctness property, not a tuning knob. If the same person hashes
 * to a different token in every query, any analysis that follows one subject
 * across queries silently produces wrong answers — and nothing in the output
 * signals that it happened.
 *
 * The reference implementation called random_bytes(16) in the constructor and
 * built a fresh instance per query, so tokens never correlated at all. Note
 * that simply hoisting the instance would not fix it under HTTP transport:
 * each request is a new PHP process, so session-stable tokens have to be
 * derived from the session identity rather than held in memory.
 */
final class SaltProvider
{
    public const LIFETIME_SESSION = 'session';

    public const LIFETIME_REQUEST = 'request';

    public const LIFETIME_CONFIG = 'config';

    private ?string $memoized = null;

    public function __construct(
        private readonly string $lifetime,
        private readonly ?string $secret = null,
        private readonly ?string $sessionId = null,
    ) {}

    public function salt(): string
    {
        return $this->memoized ??= $this->resolve();
    }

    private function resolve(): string
    {
        return match ($this->lifetime) {
            self::LIFETIME_CONFIG => $this->fromSecret(),
            self::LIFETIME_SESSION => $this->fromSession(),
            self::LIFETIME_REQUEST => random_bytes(32),
            default => throw new InvalidArgumentException(
                "Unknown salt lifetime [{$this->lifetime}]. Expected one of: session, request, config."
            ),
        };
    }

    /**
     * Tokens stable across sessions and deploys. Enables long-running analysis,
     * at the cost of being a durable pseudonym for a real person.
     */
    private function fromSecret(): string
    {
        if ($this->secret === null || $this->secret === '') {
            throw new InvalidArgumentException(
                'Salt lifetime is "config" but no secret is configured. '.
                'Set SAFE_SQL_SALT_SECRET, or choose the "session" lifetime.'
            );
        }

        return hash('sha256', 'safe-sql:config:'.$this->secret, true);
    }

    /**
     * Tokens stable within one MCP session and meaningless outside it.
     */
    private function fromSession(): string
    {
        if ($this->sessionId === null || $this->sessionId === '') {
            // Nothing durable to bind to. Falling back to a random salt costs
            // cross-query correlation but never leaks a stable pseudonym,
            // which is the safe direction to fail in.
            return random_bytes(32);
        }

        return hash('sha256', 'safe-sql:session:'.($this->secret ?? '').':'.$this->sessionId, true);
    }
}
