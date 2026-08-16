<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Anonymization;

use Afiqsazlan\SafeSql\Contracts\Anonymizer;

/**
 * Fail-closed pseudonymization of arbitrary result rows.
 *
 * The hard constraint is that this class only ever sees the *result column
 * label*, never the source column. Labels are chosen by whoever wrote the
 * query, so any scheme that trusts them alone is defeated by aliasing — in
 * both directions:
 *
 *     SELECT email AS e   FROM users   -- defeats a label denylist
 *     SELECT email AS id  FROM users   -- defeats a label allowlist
 *
 * So labels are never the only signal. Values are also matched against
 * shape patterns, which travel with the data regardless of what it is called.
 *
 * Order of decision, first match wins:
 *
 *   1. null / bool / empty       pass raw, they carry nothing
 *   2. label is known PII        pseudonymize
 *   3. value matches a pattern   pseudonymize  <- catches aliases and expressions
 *   4. value is numeric          pass raw, so counts and sums still work
 *   5. label is known safe       pass raw
 *   6. value is long free text   pseudonymize, notes routinely contain names
 *   7. anything else             pseudonymize  <- the fail-closed default
 *
 * Step 7 is the whole point: an unrecognised short string, which is what a
 * bare first name looks like, is pseudonymized rather than passed through.
 */
class PiiAnonymizer implements Anonymizer
{
    /**
     * @param  array<string, string>  $piiColumns  label => token type
     * @param  array<int, string>  $safeColumns
     * @param  array<int, string>  $safeColumnPatterns
     * @param  array<string, string>  $valuePatterns  token type => regex
     */
    public function __construct(
        protected string $salt,
        protected array $piiColumns = [],
        protected array $safeColumns = [],
        protected array $safeColumnPatterns = [],
        protected array $valuePatterns = [],
        protected int $freeTextThreshold = 120,
    ) {
        $this->piiColumns = array_change_key_case($this->piiColumns);
        $this->safeColumns = array_map(strtolower(...), $this->safeColumns);
    }

    public function anonymizeRows(array $rows): array
    {
        return array_map($this->anonymizeRow(...), $rows);
    }

    public function anonymizeRow(array $row): array
    {
        $result = [];

        foreach ($row as $label => $value) {
            $result[$label] = $this->anonymizeValue((string) $label, $value);
        }

        return $result;
    }

    public function anonymizeValue(string $label, mixed $value): mixed
    {
        if ($value === null || is_bool($value) || $value === '') {
            return $value;
        }

        $label = $this->normalizeLabel($label);

        if (isset($this->piiColumns[$label])) {
            return $this->token($this->piiColumns[$label], $value);
        }

        $string = $this->stringify($value);

        if (($type = $this->matchValuePattern($string)) !== null) {
            return $this->token($type, $value);
        }

        // Numbers pass raw. A bare number carries little identifying power
        // without a label saying what it is, and pseudonymizing them would
        // destroy counts, sums and averages — the tool's most common use.
        // Numeric PII is caught by the label map or by a value pattern above.
        if (is_int($value) || is_float($value) || is_numeric($string)) {
            return $value;
        }

        if ($this->isSafeLabel($label)) {
            return $value;
        }

        if (mb_strlen($string) > $this->freeTextThreshold) {
            return $this->token('text', $value);
        }

        return $this->token('value', $value);
    }

    /**
     * Replace PII occurrences inside a string, preserving everything else.
     *
     * Only the value patterns apply here. Label-based rules cannot: free text
     * has no column label, and the fail-closed default would replace the whole
     * string, which is the opposite of what a readable log line needs.
     */
    public function redactText(string $value): string
    {
        foreach ($this->valuePatterns as $type => $pattern) {
            $value = preg_replace_callback(
                $pattern,
                fn (array $matches): string => $this->token((string) $type, $matches[0]),
                $value
            ) ?? $value;
        }

        return $value;
    }

    /**
     * Reduce a result label to the column name it is most likely reporting on,
     * so that "MAX(created_at)" is recognised as safe and "MAX(email)" as PII.
     */
    protected function normalizeLabel(string $label): string
    {
        $label = strtolower(trim($label));
        $label = trim($label, '`"\'[]');

        // Unwrap aggregate and function calls: max(distinct `x`) -> x
        //
        // COUNT is excluded. It reports a cardinality, not the underlying
        // value, so "COUNT(DISTINCT email)" is a number and unwrapping it to
        // "email" would pseudonymize a harmless integer.
        $guard = 0;
        while ($guard++ < 5 && preg_match('/^(\w+)\(\s*(?:distinct\s+)?(.+?)\s*\)$/', $label, $matches) === 1) {
            if ($matches[1] === 'count') {
                return 'count';
            }

            $label = trim($matches[2], '`"\'');
        }

        // Drop a table qualifier: users.email -> email
        if (($dot = strrpos($label, '.')) !== false) {
            $label = substr($label, $dot + 1);
        }

        return $label;
    }

    protected function isSafeLabel(string $label): bool
    {
        if (in_array($label, $this->safeColumns, true)) {
            return true;
        }

        foreach ($this->safeColumnPatterns as $pattern) {
            if (preg_match($pattern, $label) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function matchValuePattern(string $value): ?string
    {
        foreach ($this->valuePatterns as $type => $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return (string) $type;
            }
        }

        return null;
    }

    protected function stringify(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value) ?: '';
    }

    protected function token(string $type, mixed $value): string
    {
        return "[{$type}:".$this->hash($this->stringify($value)).']';
    }

    protected function hash(string $value): string
    {
        return substr(hash_hmac('sha256', $value, $this->salt), 0, 8);
    }
}
