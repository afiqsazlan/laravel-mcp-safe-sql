<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Schema;

/**
 * Suggests how a column should be classified.
 *
 * This produces *suggestions for a human to review*, not runtime decisions,
 * and that difference licenses heuristics the anonymizer itself must not use.
 * A false positive here costs someone deleting a line from a generated config;
 * a false positive at runtime costs a tokenized column in production output.
 * So this class guesses harder, and says why, and never decides anything.
 *
 * Sampled values are inspected but never retained or reported. The command
 * that drives this prints the detected *kind*, never the data — a classifier
 * that echoed PII to a terminal would defeat the package it configures.
 */
class ColumnClassifier
{
    /**
     * Label patterns, most specific first. A column matching none of these
     * falls through to value sampling and then to review.
     *
     * @var array<string, array{0: string, 1: string}> regex => [token type, reason]
     */
    protected const LABEL_PATTERNS = [
        '/(^|_)(password|passwd|secret|api_?key|access_?token|refresh_?token|private_?key)($|_)/' => ['secret', 'credential-shaped name'],
        '/(^|_)(nric|ic_?no|national_?id|passport_?no|ssn|tax_?id)($|_)/' => ['gov_id', 'government identifier'],
        '/(^|_)(e_?mail|email)($|_)/' => ['email', 'email in the name'],
        '/(^|_)(phone|mobile|msisdn|whatsapp|telephone|contact_?no)($|_)/' => ['phone', 'phone-shaped name'],
        '/(^|_)(dob|date_?of_?birth|birth_?date|birthday)($|_)/' => ['dob', 'date of birth'],
        '/(^|_)(latitude|longitude|lat|lng|geo)($|_)/' => ['geo', 'geolocation'],
        '/(^|_)(bank_?account|account_?no|iban|swift|card_?number)($|_)/' => ['bank', 'financial identifier'],
        '/(^|_)(address|street|postcode|postal_?code|zip|city|building)($|_)/' => ['address', 'address component'],
        '/(^|_)(ip|ip_?address)($|_)/' => ['ip', 'network address'],
        '/(^|_)(first_?name|last_?name|full_?name|sur_?name|given_?name|display_?name|username)($|_)/' => ['name', 'personal name'],
    ];

    /**
     * Nouns that make a "_name" column a label for a thing, not a person.
     *
     * @var array<int, string>
     */
    protected const NON_PERSON_NAME_SUBJECTS = [
        'product', 'file', 'template', 'log', 'guard', 'table', 'column',
        'event', 'brand', 'category', 'tag', 'role', 'permission', 'queue',
        'connection', 'driver', 'channel', 'field', 'index', 'route', 'job',
        'flag_file', 'class', 'method', 'variable',
    ];

    /**
     * Shapes looked for in sampled values. Deliberately broader than the
     * anonymizer's runtime patterns — a suggestion is cheap to reject.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected const SAMPLE_PATTERNS = [
        'email' => ['/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', 'sampled values look like email addresses'],
        'gov_id' => ['/^\d{6}-?\d{2}-?\d{4}$/', 'sampled values look like national ID numbers'],
        'phone' => ['/^\+?\d[\d\s-]{7,17}$/', 'sampled values look like phone numbers'],
        'bank' => ['/^(?:\d[ -]?){13,19}$/', 'sampled values look like card or account numbers'],
        'secret' => ['/^(?:eyJ[\w-]+\.[\w-]+|sk_(?:live|test)_|gh[pousr]_)/', 'sampled values look like credentials'],
        'ip' => ['/^(?:\d{1,3}\.){3}\d{1,3}$/', 'sampled values look like IP addresses'],
        'url' => ['/^https?:\/\/\S+$/i', 'sampled values are URLs, which may resolve to personal media'],
    ];

    /**
     * @param  array<int, string>  $samples
     */
    public function classify(string $column, ?string $type = null, array $samples = []): ColumnSuggestion
    {
        $label = strtolower(trim($column));

        // Sampled data outranks the label. It is what catches an identifier
        // hiding under an innocuous name, which is the whole reason to sample.
        if (($sampled = $this->classifyBySamples($label, $samples)) !== null) {
            return $sampled;
        }

        if (($safe = $this->nonPersonName($label)) !== null) {
            return $safe;
        }

        foreach (self::LABEL_PATTERNS as $pattern => [$tokenType, $reason]) {
            if (preg_match($pattern, $label) === 1) {
                return ColumnSuggestion::pii($column, $tokenType, $reason);
            }
        }

        if ($this->isFreeText($type, $samples)) {
            return ColumnSuggestion::pii($column, 'text', 'free-text column, commonly carries names and numbers');
        }

        if (($safe = $this->looksStructural($label, $type)) !== null) {
            return $safe;
        }

        return ColumnSuggestion::review($column, 'no signal either way');
    }

    /**
     * @param  array<int, string>  $samples
     */
    protected function classifyBySamples(string $label, array $samples): ?ColumnSuggestion
    {
        $samples = array_values(array_filter($samples, static fn ($v) => is_string($v) && trim($v) !== ''));

        if (count($samples) < 3) {
            return null;
        }

        foreach (self::SAMPLE_PATTERNS as $tokenType => [$pattern, $reason]) {
            $hits = 0;

            foreach ($samples as $value) {
                if (preg_match($pattern, trim($value)) === 1) {
                    $hits++;
                }
            }

            // A clear majority, so one stray value in a mixed column does not
            // classify the whole thing.
            if ($hits / count($samples) >= 0.8) {
                return ColumnSuggestion::pii($label, $tokenType, $reason, sampled: true);
            }
        }

        return null;
    }

    protected function nonPersonName(string $label): ?ColumnSuggestion
    {
        if (! str_ends_with($label, '_name')) {
            return null;
        }

        $subject = substr($label, 0, -5);

        return in_array($subject, self::NON_PERSON_NAME_SUBJECTS, true)
            ? ColumnSuggestion::safe($label, "\"{$subject}\" names a thing, not a person")
            : null;
    }

    /**
     * @param  array<int, string>  $samples
     */
    protected function isFreeText(?string $type, array $samples): bool
    {
        if ($type !== null && preg_match('/(^|\b)(text|longtext|mediumtext|json)\b/i', $type) === 1) {
            return true;
        }

        $strings = array_filter($samples, 'is_string');

        if ($strings === []) {
            return false;
        }

        return (array_sum(array_map('mb_strlen', $strings)) / count($strings)) > 100;
    }

    protected function looksStructural(string $label, ?string $type): ?ColumnSuggestion
    {
        foreach ([
            '/(^|_)(type|status|state|code|kind|category|level|mode|stage|reason)$/' => 'enum-shaped name',
            '/^(is|has|can|should)_/' => 'boolean flag',
            '/(^|_)(count|total|sum|qty|quantity|amount|position|sequence|index)$/' => 'numeric measure',
            '/(^|_)(at|on|date|time)$/' => 'timestamp',
        ] as $pattern => $reason) {
            if (preg_match($pattern, $label) === 1) {
                return ColumnSuggestion::safe($label, $reason);
            }
        }

        if ($type !== null && preg_match('/^(tinyint\(1\)|bool)/i', $type) === 1) {
            return ColumnSuggestion::safe($label, 'boolean column');
        }

        return null;
    }
}
