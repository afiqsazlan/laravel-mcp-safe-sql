<?php

declare(strict_types=1);

use Afiqsazlan\SafeSql\Anonymization\PiiAnonymizer;

function anonymizer(string $salt = 'test-salt'): PiiAnonymizer
{
    return new PiiAnonymizer(
        salt: $salt,
        piiColumns: [
            'email' => 'email',
            'name' => 'name',
            'phone' => 'phone',
            'password' => 'secret',
            'national_id' => 'gov_id',
        ],
        safeColumns: ['id', 'status', 'created_at', 'total'],
        safeColumnPatterns: ['/_at$/', '/_id$/', '/_count$/', '/^is_/'],
        valuePatterns: [
            'email' => '/[\w.+-]+@[\w-]+\.[\w.-]+/',
            'secret' => '/\b(?:eyJ[\w-]+\.[\w-]+\.[\w-]+|sk_(?:live|test)_\w+)\b/',
            'ip' => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
        ],
        freeTextThreshold: 40,
    );
}

function token(string $label, mixed $value): mixed
{
    return anonymizer()->anonymizeValue($label, $value);
}

describe('values that carry nothing', function () {
    it('passes null, booleans and empty strings through', function (mixed $value) {
        expect(token('anything', $value))->toBe($value);
    })->with([null, true, false, '']);
});

describe('labels known to be PII', function () {
    it('pseudonymizes a mapped label', function () {
        expect(token('email', 'alice@example.com'))->toStartWith('[email:');
    });

    it('uses the mapped token type', function () {
        expect(token('password', 'hunter2'))->toStartWith('[secret:')
            ->and(token('national_id', 'A1234567'))->toStartWith('[gov_id:');
    });

    it('is case-insensitive about labels', function () {
        expect(token('EMAIL', 'alice@example.com'))->toStartWith('[email:');
    });
});

/*
 * These are the cases §4.1 of the build plan identifies as defeating the
 * reference implementation's denylist. The first also defeats the plain
 * label allowlist that §4.1 recommends as the fix.
 */
describe('alias and expression bypasses', function () {
    it('catches PII aliased to an innocuous name', function () {
        // "e" is not in any map, so a denylist returns this raw.
        expect(token('e', 'alice@example.com'))->toStartWith('[email:');
    });

    it('catches PII aliased to an explicitly safe label', function () {
        // "id" IS on the safe list, so a pure allowlist returns this raw.
        // Only value-shape detection catches it.
        expect(token('id', 'alice@example.com'))->toStartWith('[email:');
    });

    it('catches PII concatenated into an expression', function () {
        expect(token('blob', 'Alice Smith alice@example.com'))->toStartWith('[email:');
    });

    it('catches an unmapped column from an unmapped table', function () {
        expect(token('applicant_email_address', 'bob@example.com'))->toStartWith('[email:');
    });

    it('catches a credential regardless of label', function () {
        expect(token('note', 'sk_live_abc123def456'))->toStartWith('[secret:');
    });
});

describe('fail-closed default', function () {
    it('pseudonymizes an unrecognised short string, which may be a name', function () {
        expect(token('some_unmapped_column', 'Ana'))->toStartWith('[value:');
    });

    it('pseudonymizes long free text, which routinely contains PII', function () {
        $note = 'Customer called about the booking and asked us to ring Ana on 012-3456789 instead';

        expect(token('remarks', $note))->toStartWith('[text:');
    });

    it('pseudonymizes non-scalar values', function () {
        expect(token('payload', ['email' => 'a@b.co']))->toStartWith('[email:');
    });
});

describe('values that must stay readable', function () {
    it('passes numbers through so aggregates still work', function (mixed $value) {
        expect(token('some_unmapped_column', $value))->toBe($value);
    })->with([42, 3.14, 0]);

    it('passes an unaliased COUNT(*) through', function () {
        expect(token('COUNT(*)', 128))->toBe(128);
    });

    it('passes explicitly safe labels through', function () {
        expect(token('status', 'confirmed'))->toBe('confirmed');
    });

    it('passes labels matching a safe pattern through', function () {
        expect(token('cancelled_at', '2026-08-16 10:00:00'))->toBe('2026-08-16 10:00:00')
            ->and(token('customer_id', 'CUST-001'))->toBe('CUST-001')
            ->and(token('is_active', 'yes'))->toBe('yes');
    });
});

describe('label normalization', function () {
    it('unwraps aggregates around a safe column', function () {
        expect(token('MAX(created_at)', '2026-08-16'))->toBe('2026-08-16');
    });

    it('unwraps aggregates around a PII column', function () {
        expect(token('MAX(email)', 'alice@example.com'))->toStartWith('[email:');
    });

    it('unwraps DISTINCT', function () {
        expect(token('COUNT(DISTINCT email)', 5))->toBe(5);
    });

    it('strips a table qualifier', function () {
        expect(token('users.email', 'alice@example.com'))->toStartWith('[email:')
            ->and(token('orders.status', 'paid'))->toBe('paid');
    });

    it('strips quoting', function () {
        expect(token('`email`', 'alice@example.com'))->toStartWith('[email:');
    });
});

describe('token stability', function () {
    it('gives the same input the same token under one salt', function () {
        $a = anonymizer();

        expect($a->anonymizeValue('email', 'alice@example.com'))
            ->toBe($a->anonymizeValue('email', 'alice@example.com'));
    });

    it('correlates the same subject across different queries', function () {
        // The property the reference implementation lacked: a fresh anonymizer
        // built from the same salt must agree, or multi-query analysis breaks.
        expect(anonymizer('shared')->anonymizeValue('email', 'alice@example.com'))
            ->toBe(anonymizer('shared')->anonymizeValue('e', 'alice@example.com'));
    });

    it('gives different inputs different tokens', function () {
        expect(anonymizer()->anonymizeValue('email', 'alice@example.com'))
            ->not->toBe(anonymizer()->anonymizeValue('email', 'bob@example.com'));
    });

    it('does not correlate across different salts', function () {
        expect(anonymizer('salt-a')->anonymizeValue('email', 'alice@example.com'))
            ->not->toBe(anonymizer('salt-b')->anonymizeValue('email', 'alice@example.com'));
    });

    it('never emits the original value', function () {
        expect(token('email', 'alice@example.com'))->not->toContain('alice@example.com');
    });
});

describe('row handling', function () {
    it('preserves column order and keys', function () {
        $row = ['id' => 1, 'email' => 'a@b.co', 'status' => 'paid'];

        expect(array_keys(anonymizer()->anonymizeRow($row)))->toBe(['id', 'email', 'status']);
    });

    it('anonymizes every row', function () {
        $rows = [['email' => 'a@b.co'], ['email' => 'c@d.co']];

        expect(anonymizer()->anonymizeRows($rows))->each->toHaveKey('email')
            ->and(anonymizer()->anonymizeRows($rows)[0]['email'])->toStartWith('[email:');
    });

    it('handles an empty result set', function () {
        expect(anonymizer()->anonymizeRows([]))->toBe([]);
    });
});
