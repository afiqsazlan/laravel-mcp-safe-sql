<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Servers;

use Afiqsazlan\SafeSql\Exceptions\UnknownProfileException;
use Afiqsazlan\SafeSql\Instructions\InstructionComposer;
use Afiqsazlan\SafeSql\Profiles\Profile;
use Afiqsazlan\SafeSql\Resources\DatabaseSchemaResource;
use Afiqsazlan\SafeSql\Tools\DescribeTableTool;
use Afiqsazlan\SafeSql\Tools\ExecuteSqlTool;
use Afiqsazlan\SafeSql\Tools\TelescopeTool;
use Laravel\Mcp\Server;

/**
 * Base server driven entirely by a named profile.
 *
 * Laravel MCP registers servers by class, so a consuming application declares
 * one thin subclass per endpoint:
 *
 *     class ResearchServer extends SqlMcpServer { protected string $profile = 'research'; }
 *     class DebugServer    extends SqlMcpServer { protected string $profile = 'debug'; }
 *
 *     Mcp::web('mcp/research', ResearchServer::class)->middleware([...]);
 *
 * Keep them as separate endpoints. Merging them would collapse two different
 * sensitivity tiers into one grant, and server instructions stay resident for
 * the whole session, so a merged server makes every conversation carry both
 * instruction sets.
 */
abstract class SqlMcpServer extends Server
{
    /**
     * Key under safe-sql.profiles.
     */
    protected string $profile = '';

    /**
     * @var array<string, class-string>
     */
    protected const TOOL_MAP = [
        'sql' => ExecuteSqlTool::class,
        'schema' => DescribeTableTool::class,
        'telescope' => TelescopeTool::class,
    ];

    protected function boot(): void
    {
        $profile = Profile::make($this->profile);

        $this->tools = $this->resolveTools($profile);

        // The schema digest rides along with the schema tool. It is a resource
        // rather than a tool because it is a document the client can read once,
        // not an action.
        if ($profile->exposes('schema')) {
            $this->resources = [new DatabaseSchemaResource($profile)];
        }

        // Package instructions plus the application's, in that order. The
        // pseudonymization rules in particular are a correctness fact, not
        // documentation, so they must survive an application supplying its own.
        $this->instructions = (new InstructionComposer)->compose($profile);
    }

    /**
     * @return array<int, object>
     */
    protected function resolveTools(Profile $profile): array
    {
        $tools = [];

        foreach ($profile->tools as $tool) {
            $class = static::TOOL_MAP[$tool] ?? null;

            if ($class === null) {
                throw UnknownProfileException::unknownTool($tool, $profile->name);
            }

            $tools[] = new $class($profile);
        }

        return $tools;
    }
}
