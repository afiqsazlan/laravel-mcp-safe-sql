<?php

declare(strict_types=1);

use Afiqsazlan\SafeSql\Anonymization\NullAnonymizer;
use Afiqsazlan\SafeSql\Contracts\Anonymizer;
use Afiqsazlan\SafeSql\Exceptions\UnsafeQueryException;
use Afiqsazlan\SafeSql\Sql\QueryExecutor;
use Afiqsazlan\SafeSql\Sql\QueryValidator;
use Illuminate\Database\ConnectionInterface;

const LIMITS = [
    'max_rows' => 500,
    'timeout_ms' => 30000,
    'max_cell_length' => 20,
];

/**
 * Runs the executor against a stub connection and returns the SQL it actually
 * sent, which is what the LIMIT-injection rules are really about.
 *
 * @param  array<int, mixed>  $returns
 */
function executedSql(string $sql, array $returns = [], ?Anonymizer $anonymizer = null): string
{
    $captured = '';

    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('statement')->andReturnTrue();
    $connection->shouldReceive('select')
        ->andReturnUsing(function (string $query) use (&$captured, $returns) {
            $captured = $query;

            return $returns;
        });

    (new QueryExecutor(
        $connection,
        new QueryValidator,
        $anonymizer ?? new NullAnonymizer,
        LIMITS,
    ))->execute($sql);

    return $captured;
}

describe('row limit injection', function () {
    it('appends a LIMIT when the statement has none', function () {
        expect(executedSql('SELECT id FROM users'))
            ->toBe("SELECT id FROM users\nLIMIT 500");
    });

    it('leaves an existing top-level LIMIT alone', function () {
        expect(executedSql('SELECT id FROM users LIMIT 10'))
            ->toBe('SELECT id FROM users LIMIT 10');
    });

    it('still bounds the outer query when only a subquery has a LIMIT', function () {
        expect(executedSql('SELECT id FROM (SELECT id FROM users LIMIT 10) t'))
            ->toBe("SELECT id FROM (SELECT id FROM users LIMIT 10) t\nLIMIT 500");
    });

    it('is not fooled by the word LIMIT inside a string literal', function () {
        // Treating this as an existing LIMIT would leave the query unbounded.
        expect(executedSql("SELECT id FROM users WHERE note = 'LIMIT'"))
            ->toBe("SELECT id FROM users WHERE note = 'LIMIT'\nLIMIT 500");
    });

    it('is not fooled by the word LIMIT inside a comment', function () {
        expect(executedSql('SELECT id FROM users -- LIMIT 10'))
            ->toBe("SELECT id FROM users -- LIMIT 10\nLIMIT 500");
    });

    it('appends on a new line so a trailing comment cannot swallow the clause', function () {
        // " LIMIT 500" appended after a line comment would be commented out,
        // leaving the query unbounded.
        foreach (['SELECT id FROM users -- note', 'SELECT id FROM users # note'] as $sql) {
            expect(executedSql($sql))->toEndWith("\nLIMIT 500");
        }
    });

    it('strips a trailing semicolon before appending', function () {
        expect(executedSql('SELECT id FROM users;'))
            ->toBe("SELECT id FROM users\nLIMIT 500");
    });

    it('does not touch EXPLAIN, whose plan a LIMIT would change', function () {
        expect(executedSql('EXPLAIN SELECT id FROM users'))
            ->toBe('EXPLAIN SELECT id FROM users');
    });

    it('does not touch metadata statements', function () {
        expect(executedSql('DESCRIBE users'))->toBe('DESCRIBE users');
    });
});

describe('execution guardrails', function () {
    it('sets a server-side statement timeout', function () {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('statement')
            ->once()
            ->with('SET SESSION max_execution_time = 30000')
            ->andReturnTrue();
        $connection->shouldReceive('select')->andReturn([]);

        (new QueryExecutor($connection, new QueryValidator, new NullAnonymizer, LIMITS))
            ->execute('SELECT 1');
    });

    it('survives a connection that rejects max_execution_time', function () {
        // MariaDB and MySQL below 5.7.8 do not support it.
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('statement')->andThrow(new RuntimeException('unknown variable'));
        $connection->shouldReceive('select')->andReturn([['id' => 1]]);

        $result = (new QueryExecutor($connection, new QueryValidator, new NullAnonymizer, LIMITS))
            ->execute('SELECT 1');

        expect($result->rowCount)->toBe(1);
    });

    it('refuses to execute a statement that fails validation', function () {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldNotReceive('select');

        expect(fn () => (new QueryExecutor($connection, new QueryValidator, new NullAnonymizer, LIMITS))
            ->execute('DROP TABLE users'))
            ->toThrow(UnsafeQueryException::class);
    });
});

describe('result shaping', function () {
    it('truncates oversized cells', function () {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('statement')->andReturnTrue();
        $connection->shouldReceive('select')->andReturn([
            (object) ['bio' => str_repeat('a', 50)],
        ]);

        $result = (new QueryExecutor($connection, new QueryValidator, new NullAnonymizer, LIMITS))
            ->execute('SELECT bio FROM users');

        expect($result->rows[0]['bio'])->toBe(str_repeat('a', 20).'...');
    });

    it('leaves non-string cells untouched', function () {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('statement')->andReturnTrue();
        $connection->shouldReceive('select')->andReturn([
            (object) ['id' => 7, 'score' => 1.5, 'deleted_at' => null],
        ]);

        $result = (new QueryExecutor($connection, new QueryValidator, new NullAnonymizer, LIMITS))
            ->execute('SELECT id, score, deleted_at FROM users');

        expect($result->rows[0])->toBe(['id' => 7, 'score' => 1.5, 'deleted_at' => null]);
    });

    it('reports the result as truncated once the row cap is reached', function () {
        $rows = array_fill(0, 500, (object) ['id' => 1]);

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('statement')->andReturnTrue();
        $connection->shouldReceive('select')->andReturn($rows);

        $result = (new QueryExecutor($connection, new QueryValidator, new NullAnonymizer, LIMITS))
            ->execute('SELECT id FROM users');

        expect($result->truncated)->toBeTrue()
            ->and($result->rowCount)->toBe(500);
    });

    it('exposes the column labels of the result', function () {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('statement')->andReturnTrue();
        $connection->shouldReceive('select')->andReturn([(object) ['id' => 1, 'email' => 'a@b.c']]);

        $result = (new QueryExecutor($connection, new QueryValidator, new NullAnonymizer, LIMITS))
            ->execute('SELECT id, email FROM users');

        expect($result->columns())->toBe(['id', 'email']);
    });

    it('routes every row through the anonymizer', function () {
        $anonymizer = Mockery::mock(Anonymizer::class);
        $anonymizer->shouldReceive('anonymizeRows')
            ->once()
            ->andReturn([['email' => '[email:deadbeef]']]);

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('statement')->andReturnTrue();
        $connection->shouldReceive('select')->andReturn([(object) ['email' => 'real@example.com']]);

        $result = (new QueryExecutor($connection, new QueryValidator, $anonymizer, LIMITS))
            ->execute('SELECT email FROM users');

        expect($result->rows[0]['email'])->toBe('[email:deadbeef]');
    });
});
