<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Anonymization;

use Afiqsazlan\SafeSql\Contracts\Anonymizer;

/**
 * Passes every value through untouched.
 *
 * This is what a profile with 'anonymize' => false resolves to. The reference
 * implementation expressed the same idea as a subclass that overrode
 * anonymize() to a no-op, which welded a policy decision to the class
 * hierarchy; making it a swappable collaborator keeps the decision in config
 * where it can be audited.
 */
class NullAnonymizer implements Anonymizer
{
    public function anonymizeRows(array $rows): array
    {
        return $rows;
    }

    public function anonymizeRow(array $row): array
    {
        return $row;
    }

    public function anonymizeValue(string $label, mixed $value): mixed
    {
        return $value;
    }

    public function redactText(string $value): string
    {
        return $value;
    }
}
