<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Contracts;

interface Anonymizer
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function anonymizeRows(array $rows): array;

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function anonymizeRow(array $row): array;

    /**
     * Pseudonymize a single value whose column label is unknown or absent,
     * such as a value pulled out of a serialized Telescope payload.
     */
    public function anonymizeValue(string $label, mixed $value): mixed;

    /**
     * Replace PII *within* a string, leaving the rest of it readable.
     *
     * Distinct from anonymizeValue, which replaces the whole value. Free text
     * such as an exception message or a log line has to stay legible to be
     * worth returning at all: "User [email:a3f9] not found" is useful,
     * "[text:a3f9]" is not.
     */
    public function redactText(string $value): string;
}
