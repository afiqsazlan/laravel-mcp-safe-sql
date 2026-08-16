<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Exceptions;

use InvalidArgumentException;

class UnknownProfileException extends InvalidArgumentException
{
    public static function named(string $name): self
    {
        return new self(
            "No safe-sql profile named [{$name}] is configured. ".
            'Add it under the "profiles" key in config/safe-sql.php.'
        );
    }

    public static function unknownTool(string $tool, string $profile): self
    {
        return new self(
            "Profile [{$profile}] lists an unknown tool [{$tool}]. ".
            'Available tools are: sql, schema, telescope.'
        );
    }
}
