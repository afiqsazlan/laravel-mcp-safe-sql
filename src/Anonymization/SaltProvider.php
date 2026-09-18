<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Anonymization;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Decides how long a pseudonym stays stable.
 *
 * This is a correctness property, not a tuning knob. If the same person hashes
 * to a different token in every query, analysis that follows one subject across
 * queries silently produces wrong answers, and nothing in the output says so.
 *
 * Laravel MCP 1.0 made HTTP transport stateless: there is no MCP session to
 * anchor to, and each request is a fresh process. So "the same conversation"
 * cannot be recovered on the server. What can be recovered is who is asking,
 * and when — hence the default anchors tokens to the authenticated user and a
 * time window:
 *
 *   - one analyst sees stable tokens for a whole day, across any number of
 *     queries and conversations;
 *   - two analysts see *different* tokens for the same value, so they cannot
 *     pool pseudonyms to reconstruct more than either was shown;
 *   - tokens rotate at a boundary that is chosen and documented, rather than
 *     at an OAuth token refresh nobody sees.
 */
final class SaltProvider
{
    public const LIFETIME_USER = 'user';

    public const LIFETIME_REQUEST = 'request';

    public const LIFETIME_CONFIG = 'config';

    /**
     * Accepted for configs published before 1.0, where it meant "per MCP
     * session". Sessions no longer exist, so it now means the same as "user".
     */
    public const LIFETIME_SESSION = 'session';

    /**
     * @var array<string, string> window => date format bucketing time into it
     */
    private const WINDOWS = [
        'hour' => 'Y-m-d\TH',
        'day' => 'Y-m-d',
        'week' => 'o-\WW',
        'month' => 'Y-m',
    ];

    /**
     * One salt per PHP process. Used only where a process *is* a session —
     * the stdio transport, which runs one long-lived process per connection.
     */
    private static ?string $processSalt = null;

    private ?string $memoized = null;

    public function __construct(
        private readonly string $lifetime,
        private readonly ?string $secret = null,
        private readonly ?string $userId = null,
        private readonly string $window = 'day',
        private readonly bool $processIsSession = false,
        private readonly ?DateTimeInterface $now = null,
    ) {}

    public function salt(): string
    {
        return $this->memoized ??= $this->resolve();
    }

    private function resolve(): string
    {
        return match ($this->lifetime) {
            self::LIFETIME_USER, self::LIFETIME_SESSION => $this->fromUser(),
            self::LIFETIME_CONFIG => $this->fromSecret(),
            self::LIFETIME_REQUEST => random_bytes(32),
            default => throw new InvalidArgumentException(
                "Unknown salt lifetime [{$this->lifetime}]. Expected one of: user, request, config."
            ),
        };
    }

    private function fromUser(): string
    {
        if ($this->userId === null || $this->userId === '') {
            // No identity to anchor to. Under stdio the process is the
            // session, so a process-wide salt gives exactly the intended
            // scope. Anywhere else — an unauthenticated HTTP endpoint on a
            // long-lived worker — a process salt would correlate across
            // unrelated callers, so fall back to per-call instead: losing
            // correlation is the safe direction to fail in.
            return $this->processIsSession
                ? self::$processSalt ??= random_bytes(32)
                : random_bytes(32);
        }

        return hash(
            'sha256',
            'safe-sql:user:'.($this->secret ?? '').':'.$this->userId.':'.$this->bucket(),
            true,
        );
    }

    /**
     * Tokens stable across users, sessions and deploys. Enables long-running
     * shared analysis, at the cost of a durable pseudonym for a real person.
     */
    private function fromSecret(): string
    {
        if ($this->secret === null || $this->secret === '') {
            throw new InvalidArgumentException(
                'Salt lifetime is "config" but no secret is configured. '.
                'Set SAFE_SQL_SALT_SECRET, or choose the "user" lifetime.'
            );
        }

        return hash('sha256', 'safe-sql:config:'.$this->secret, true);
    }

    /**
     * The current time window, in UTC so the boundary does not move with a
     * server's timezone setting.
     */
    private function bucket(): string
    {
        $format = self::WINDOWS[$this->window] ?? throw new InvalidArgumentException(
            "Unknown salt window [{$this->window}]. Expected one of: ".implode(', ', array_keys(self::WINDOWS)).'.'
        );

        $now = $this->now ?? new DateTimeImmutable;

        return DateTimeImmutable::createFromInterface($now)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format($format);
    }

    /**
     * For tests only: forget the process-wide salt.
     */
    public static function flushProcessSalt(): void
    {
        self::$processSalt = null;
    }
}
