<?php

declare(strict_types=1);

use Afiqsazlan\SafeSql\Exceptions\UnsafeQueryException;
use Afiqsazlan\SafeSql\Sql\QueryValidator;

beforeEach(function () {
    $this->validator = new QueryValidator;
});

function accepts(string $sql): void
{
    test()->validator->validate($sql);
    expect(true)->toBeTrue();
}

function rejects(string $sql): void
{
    expect(fn () => test()->validator->validate($sql))
        ->toThrow(UnsafeQueryException::class);
}

describe('read-only statements', function () {
    it('accepts a plain SELECT', function () {
        accepts('SELECT id FROM users');
    });

    it('accepts a trailing semicolon', function () {
        accepts('SELECT id FROM users;');
    });

    it('accepts a common table expression', function () {
        accepts('WITH recent AS (SELECT id FROM users) SELECT * FROM recent');
    });

    it('accepts plain EXPLAIN', function () {
        accepts('EXPLAIN SELECT id FROM users');
    });

    it('accepts DESCRIBE and SHOW COLUMNS', function (string $sql) {
        accepts($sql);
    })->with([
        'DESCRIBE users',
        'DESC users',
        'SHOW COLUMNS FROM users',
    ]);

    it('accepts columns whose names contain forbidden keywords', function () {
        accepts('SELECT create_time, updated_at, dropoff_id FROM sessions');
    });

    it('accepts forbidden keywords inside string literals', function () {
        accepts("SELECT id FROM users WHERE note = 'DROP TABLE users'");
    });

    it('accepts forbidden keywords inside backtick identifiers', function () {
        accepts('SELECT `drop` FROM `update`');
    });

    it('accepts a semicolon inside a string literal', function () {
        accepts("SELECT id FROM users WHERE bio = 'a;b'");
    });

    it('accepts an inert block comment', function () {
        accepts('SELECT /* DROP TABLE users */ id FROM users');
    });
});

describe('write and execute statements', function () {
    it('rejects data modification', function (string $sql) {
        rejects($sql);
    })->with([
        'INSERT INTO users (id) VALUES (1)',
        'UPDATE users SET name = "x"',
        'DELETE FROM users',
        'TRUNCATE TABLE users',
        'REPLACE INTO users (id) VALUES (1)',
    ]);

    it('rejects schema modification', function (string $sql) {
        rejects($sql);
    })->with([
        'DROP TABLE users',
        'ALTER TABLE users ADD COLUMN x INT',
        'CREATE TABLE x (id INT)',
        'RENAME TABLE a TO b',
    ]);

    it('rejects privilege and session statements', function (string $sql) {
        rejects($sql);
    })->with([
        'GRANT ALL ON *.* TO x',
        'REVOKE ALL ON *.* FROM x',
        'SET SESSION max_execution_time = 0',
        'LOCK TABLES users READ',
        'FLUSH PRIVILEGES',
        'KILL 1',
        'USE other_database',
    ]);

    it('rejects procedure execution', function (string $sql) {
        rejects($sql);
    })->with([
        'CALL some_procedure()',
        'EXECUTE stmt',
        'PREPARE stmt FROM "SELECT 1"',
    ]);

    it('rejects a write hidden after a leading SELECT', function () {
        rejects('SELECT id FROM users UNION SELECT 1 INTO OUTFILE "/tmp/x"');
    });
});

describe('filesystem access', function () {
    it('rejects filesystem reads and writes', function (string $sql) {
        rejects($sql);
    })->with([
        'SELECT LOAD_FILE("/etc/passwd")',
        'SELECT * FROM users INTO OUTFILE "/tmp/dump"',
        'SELECT * FROM users INTO DUMPFILE "/tmp/dump"',
        'LOAD DATA INFILE "/tmp/x" INTO TABLE users',
    ]);
});

describe('multi-statement queries', function () {
    it('rejects stacked statements', function () {
        rejects('SELECT 1; DROP TABLE users');
    });

    it('rejects stacked statements separated by a newline', function () {
        rejects("SELECT 1;\nDROP TABLE users");
    });
});

describe('EXPLAIN variants that execute', function () {
    it('rejects EXPLAIN ANALYZE, which runs the statement', function () {
        rejects('EXPLAIN ANALYZE SELECT id FROM users');
    });

    it('rejects EXPLAIN FOR CONNECTION, which reads another session', function () {
        rejects('EXPLAIN FOR CONNECTION 42');
    });
});

/*
 * Both cases below pass validation in the reference implementation this
 * package was extracted from. They are the reason SqlSanitizer mirrors
 * MySQL's comment rules rather than approximating them.
 */
describe('comment-based bypasses', function () {
    it('rejects MySQL executable comments, whose contents actually run', function () {
        rejects('SELECT * FROM users /*!INTO OUTFILE "/tmp/x" */');
    });

    it('rejects an executable comment regardless of version gate', function () {
        rejects('SELECT 1 /*!50000 DROP TABLE users */');
    });

    it('does not treat "--" without whitespace as a comment', function () {
        // MySQL reads "--1" as arithmetic, so the semicolon is real and this
        // is two statements. Stripping "--.*$" greedily would hide it.
        rejects('SELECT 1 --1; DROP TABLE users');
    });

    it('still treats "-- " with whitespace as a comment', function () {
        accepts('SELECT id FROM users -- trailing note');
    });

    it('still treats "#" as a comment', function () {
        accepts('SELECT id FROM users # trailing note');
    });
});

describe('plan and metadata detection', function () {
    it('recognises statements that must not receive an injected LIMIT', function (string $sql) {
        expect((new QueryValidator)->isPlanOrMetadata($sql))->toBeTrue();
    })->with([
        'EXPLAIN SELECT id FROM users',
        'DESCRIBE users',
        'SHOW COLUMNS FROM users',
    ]);

    it('treats an ordinary SELECT as limitable', function () {
        expect((new QueryValidator)->isPlanOrMetadata('SELECT id FROM users'))->toBeFalse();
    });
});
