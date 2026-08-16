<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Resources;

use Afiqsazlan\SafeSql\Profiles\Profile;
use Afiqsazlan\SafeSql\Schema\SchemaContextLoader;
use Illuminate\Support\Facades\Config;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class DatabaseSchemaResource extends Resource
{
    protected string $name = 'database-schema';

    protected string $mimeType = 'text/plain';

    public function __construct(protected Profile $profile) {}

    public function uri(): string
    {
        return Config::get('safe-sql.uri_scheme', 'safe-sql').'://database/schema';
    }

    public function description(): string
    {
        return $this->profile->describes('schema-resource')
            ?? 'Schema of the main database tables: columns, types, keys and foreign key '
              .'references. Read this before writing queries to avoid a series of '
              .'describe-table calls.';
    }

    public function handle(Request $request, SchemaContextLoader $loader): Response
    {
        return Response::text($loader->load($this->profile));
    }
}
