<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Sql;

use Afiqsazlan\SafeSql\Exceptions\UnsafeQueryException;

/**
 * Decides whether a statement is read-only.
 *
 * This class is the package's security contract. It is pure string analysis
 * and never touches a connection, so every rule in it is cheaply testable —
 * see tests/Unit/Sql/QueryValidatorTest.php, which is the authoritative
 * description of what is and is not allowed.
 */
class QueryValidator
{
    /**
     * Statements and constructs that write, execute, or reach the filesystem.
     *
     * The final "must start with SELECT" check already rejects most of these
     * as leading keywords. They are listed anyway because they also need to be
     * rejected when they appear part-way through an otherwise SELECT-shaped
     * statement.
     *
     * @var array<int, string>
     */
    protected const FORBIDDEN_PATTERNS = [
        '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|REPLACE|RENAME|GRANT|REVOKE)\b/i',
        '/\b(LOCK|UNLOCK|CALL|EXEC|EXECUTE|SET|PREPARE|DEALLOCATE|HANDLER|DO|USE)\b/i',
        '/\b(FLUSH|KILL|SHUTDOWN|RESET|OPTIMIZE|REPAIR|CHECKSUM|INSTALL|UNINSTALL)\b/i',
        '/\bLOAD_FILE\b/i',
        '/\bLOAD\s+DATA\b/i',
        '/\bINTO\s+(OUT|DUMP)FILE\b/i',
    ];

    /**
     * Metadata statements that are read-only but do not begin with SELECT.
     */
    protected const METADATA_PATTERN = '/^\s*(DESCRIBE|DESC|SHOW\s+COLUMNS\s+FROM)\b/i';

    /**
     * @throws UnsafeQueryException
     */
    public function validate(string $sql): void
    {
        if (SqlSanitizer::hasExecutableComment($sql)) {
            throw UnsafeQueryException::executableComment();
        }

        $normalized = SqlSanitizer::normalize($sql);
        $withoutComments = trim(SqlSanitizer::stripComments($normalized));
        $withoutStrings = SqlSanitizer::stripStringLiterals($withoutComments);

        if (str_contains($withoutStrings, ';')) {
            throw UnsafeQueryException::multiStatement();
        }

        // DESCRIBE / SHOW COLUMNS are read-only but would fail the checks below.
        if (preg_match(self::METADATA_PATTERN, $withoutComments) === 1) {
            return;
        }

        // EXPLAIN ANALYZE runs the statement; EXPLAIN FOR CONNECTION reads
        // another session's query. Plain EXPLAIN is fine.
        if (preg_match('/^\s*EXPLAIN\s+(ANALYZE|FOR\s+CONNECTION)\b/i', $withoutStrings) === 1) {
            throw UnsafeQueryException::explainExecutes();
        }

        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $withoutStrings) === 1) {
                throw UnsafeQueryException::notReadOnly();
            }
        }

        if (preg_match('/^\s*(SELECT|WITH|EXPLAIN\s+(SELECT|WITH))\b/i', $withoutStrings) !== 1) {
            throw UnsafeQueryException::notReadOnly();
        }
    }

    /**
     * Whether a statement is metadata or EXPLAIN, for which an injected LIMIT
     * would be meaningless or would change the plan being reported.
     */
    public function isPlanOrMetadata(string $sql): bool
    {
        $withoutComments = trim(SqlSanitizer::stripComments(SqlSanitizer::normalize($sql)));

        return preg_match('/^\s*(DESCRIBE|DESC|SHOW\s+COLUMNS\s+FROM|EXPLAIN)\b/i', $withoutComments) === 1;
    }
}
