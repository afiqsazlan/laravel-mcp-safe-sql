<?php

declare(strict_types=1);

use Afiqsazlan\SafeSql\Schema\ColumnClassifier;
use Afiqsazlan\SafeSql\Schema\ColumnSuggestion;

function suggest(string $column, ?string $type = null, array $samples = []): ColumnSuggestion
{
    return (new ColumnClassifier)->classify($column, $type, $samples);
}

describe('classification by name', function () {
    it('recognises PII-shaped names', function (string $column, string $type) {
        expect(suggest($column))->bucket->toBe('pii')->tokenType->toBe($type);
    })->with([
        ['customer_email_address', 'email'],
        ['source_customer_phone', 'phone'],
        ['nric_no', 'gov_id'],
        ['api_consumer_secret', 'secret'],
        ['address_line_1', 'address'],
        ['building_name', 'address'],
        ['date_of_birth', 'dob'],
        ['display_name', 'name'],
        ['source_shipping_first_name', 'name'],
    ]);

    it('recognises structural columns as safe', function (string $column) {
        expect(suggest($column))->bucket->toBe('safe');
    })->with([
        'enrollment_status', 'payment_type', 'error_code', 'is_webhook_active',
        'delivered_count', 'current_step_position', 'scheduled_at',
    ]);

    it('does not treat a thing-name as a person-name', function (string $column) {
        // "product_name" is not PII; "first_name" is. Both end in _name.
        expect(suggest($column))->bucket->toBe('safe');
    })->with(['product_name', 'template_name', 'file_name', 'log_name', 'guard_name']);

    it('admits when it has no signal', function () {
        expect(suggest('bucket'))->bucket->toBe('review');
    });
});

describe('classification by sampled data', function () {
    it('catches a phone number hiding under an id-shaped name', function () {
        // The sihate case: customers.whatsapp_id holds E.164 digits, passes
        // the runtime anonymizer as a number, and no name-based map catches it.
        $suggestion = suggest('whatsapp_id', 'varchar(255)', [
            '60123456789', '60198887777', '60112223333', '60177778888',
        ]);

        expect($suggestion->bucket)->toBe('pii')
            ->and($suggestion->tokenType)->toBe('phone')
            ->and($suggestion->fromSampledData)->toBeTrue();
    });

    it('lets data outrank an innocuous name', function () {
        $suggestion = suggest('reference', 'varchar(255)', [
            'a@b.co', 'c@d.co', 'e@f.co', 'g@h.co',
        ]);

        expect($suggestion->tokenType)->toBe('email')
            ->and($suggestion->fromSampledData)->toBeTrue();
    });

    it('requires a clear majority before classifying', function () {
        // One stray address in a status column must not classify the column.
        $suggestion = suggest('status', 'varchar(50)', [
            'active', 'active', 'churned', 'pending', 'a@b.co',
        ]);

        expect($suggestion->bucket)->toBe('safe');
    });

    it('ignores too small a sample', function () {
        expect(suggest('reference', 'varchar(255)', ['a@b.co'])->fromSampledData)->toBeFalse();
    });
});

describe('free text', function () {
    it('treats text columns as carrying PII', function () {
        expect(suggest('notes', 'text'))->bucket->toBe('pii')->tokenType->toBe('text');
    });

    it('treats long sampled values as free text', function () {
        expect(suggest('remark', 'varchar(500)', [str_repeat('a', 300), str_repeat('b', 250), str_repeat('c', 400)]))
            ->tokenType->toBe('text');
    });
});

describe('reporting', function () {
    it('always explains itself', function (string $column) {
        expect(suggest($column)->reason)->not->toBeEmpty();
    })->with(['customer_email_address', 'product_name', 'bucket', 'is_active']);
});
