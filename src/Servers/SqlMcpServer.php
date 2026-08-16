<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Servers;

use Afiqsazlan\SafeSql\Exceptions\UnknownProfileException;
use Afiqsazlan\SafeSql\Profiles\Profile;
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
