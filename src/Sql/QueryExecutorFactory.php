<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Sql;

use Afiqsazlan\SafeSql\Anonymization\AnonymizerFactory;
use Afiqsazlan\SafeSql\Profiles\Profile;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Config;

class QueryExecutorFactory
{
    public function __construct(
        protected DatabaseManager $database,
        protected AnonymizerFactory $anonymizers,
    ) {}

    public function make(Profile $profile, ?string $sessionId = null): QueryExecutor
    {
        /** @var array<string, int> $limits */
        $limits = Config::get('safe-sql.limits', []);

        return new QueryExecutor(
            connection: $this->database->connection($profile->connection),
            validator: new QueryValidator,
            anonymizer: $this->anonymizers->make($profile, $sessionId),
            limits: $limits,
        );
    }
}
