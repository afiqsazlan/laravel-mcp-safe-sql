<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Profiles;

use Afiqsazlan\SafeSql\Exceptions\UnknownProfileException;
use Illuminate\Support\Facades\Config;

/**
 * A resolved profile: which connection to read, whether to pseudonymize, and
 * which tools to expose.
 *
 * These three concerns are independent, and welding them together is what the
 * reference implementation did by expressing "no anonymization" as a subclass.
 * Keeping them as config means the safety property of a given endpoint is
 * declared and auditable rather than being a consequence of which class
 * happened to be registered at a route.
 */
final class Profile
{
    /**
     * @param  array<int, string>  $tools
     * @param  array<string, string>  $descriptions
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $connection,
        public readonly bool $anonymize,
        public readonly array $tools,
        public readonly ?string $instructions,
        public readonly array $descriptions = [],
        public readonly ?string $label = null,
    ) {}

    public static function make(string $name): self
    {
        $config = Config::get("safe-sql.profiles.{$name}");

        if (! is_array($config)) {
            throw UnknownProfileException::named($name);
        }

        return new self(
            name: $name,
            connection: $config['connection'] ?? null,
            // Absent means anonymize. Turning it off must be a deliberate,
            // visible act rather than something you get by forgetting a key.
            anonymize: (bool) ($config['anonymize'] ?? true),
            tools: $config['tools'] ?? ['sql', 'schema'],
            instructions: $config['instructions'] ?? null,
            descriptions: $config['descriptions'] ?? [],
            label: $config['label'] ?? null,
        );
    }

    /**
     * A short phrase naming which database this is and how it treats values.
     *
     * Surfaced in tool descriptions, tool responses and server instructions.
     * A client connected to two of these servers sees the same tool names
     * under each, and picks between them largely on wording — so every place
     * the model looks should say plainly which database it is talking to.
     * Choosing wrong is not a nuisance: a product question answered from
     * staging returns a plausible number that is simply false.
     */
    public function sourceDescription(): string
    {
        $label = $this->label ?? $this->name;

        return $label.($this->anonymize
            ? ' (values pseudonymized)'
            : ' (literal values, no pseudonymization)');
    }

    public function describes(string $tool): ?string
    {
        return $this->descriptions[$tool] ?? null;
    }

    public function exposes(string $tool): bool
    {
        return in_array($tool, $this->tools, true);
    }
}
