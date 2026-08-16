<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Sql;

/**
 * Reduces SQL to a form safe to pattern-match against.
 *
 * Every method here exists so that a keyword check cannot be fooled by hiding
 * the keyword somewhere the checker treats as inert but MySQL does not. The
 * comment rules deliberately mirror MySQL's own, because any divergence is a
 * bypass in one direction or the other.
 */
final class SqlSanitizer
{
    /**
     * Strip comments using MySQL's rules.
     *
     * Two subtleties, both of which are bypasses if got wrong:
     *
     * 1. "/*! ... *\/" is an executable comment — MySQL runs its contents. It is
     *    deliberately NOT stripped here; callers reject it outright instead. If
     *    it were stripped, "SELECT * FROM t /*!INTO OUTFILE '/tmp/x'*\/" would
     *    pass every keyword check and then write a file.
     * 2. MySQL only treats "--" as a comment when followed by whitespace.
     *    "--" without it is arithmetic negation. Stripping the greedy form
     *    would let "SELECT 1 --1; DROP TABLE t" hide its semicolon from the
     *    multi-statement check while MySQL still sees two statements.
     */
    public static function stripComments(string $sql): string
    {
        // Block comments, excluding executable ones.
        $sql = preg_replace('/\/\*(?!!)[\s\S]*?\*\//', ' ', $sql) ?? $sql;

        // "-- " to end of line, and a bare "--" terminating a line.
        $sql = preg_replace('/--[ \t\f].*$/m', ' ', $sql) ?? $sql;
        $sql = preg_replace('/--\r?$/m', ' ', $sql) ?? $sql;

        // "#" to end of line.
        return preg_replace('/#.*$/m', ' ', $sql) ?? $sql;
    }

    /**
     * Replace string literals and backtick-quoted identifiers with empty
     * equivalents, so their contents cannot trip keyword or semicolon checks.
     */
    public static function stripStringLiterals(string $sql): string
    {
        $sql = preg_replace("/'([^'\\\\]|\\\\.|'')*'/", "''", $sql) ?? $sql;
        $sql = preg_replace('/"([^"\\\\]|\\\\.|"")*"/', '""', $sql) ?? $sql;

        return preg_replace('/`[^`]*`/', '``', $sql) ?? $sql;
    }

    /**
     * Whether the statement contains a MySQL executable comment.
     *
     * Checked against the raw string rather than a stripped one. A "/*!"
     * appearing inside a string literal is therefore a false positive, which
     * is the correct direction to be wrong in.
     */
    public static function hasExecutableComment(string $sql): bool
    {
        return str_contains($sql, '/*!');
    }

    /**
     * Trim surrounding whitespace and any trailing semicolons.
     */
    public static function normalize(string $sql): string
    {
        return trim(rtrim(trim($sql), ';'));
    }

    /**
     * Collapse parenthesised groups to "()" so that clauses inside subqueries
     * are invisible to checks that should only consider the outer statement.
     */
    public static function collapseParentheses(string $sql): string
    {
        $pattern = '/\([^()]*\)/';
        $iterations = 0;

        while (preg_match($pattern, $sql) === 1 && $iterations++ < 50) {
            $sql = preg_replace($pattern, '()', $sql) ?? $sql;
        }

        return $sql;
    }
}
