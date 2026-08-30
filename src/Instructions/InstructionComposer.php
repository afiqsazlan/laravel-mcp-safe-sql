<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Instructions;

use Afiqsazlan\SafeSql\Profiles\Profile;
use RuntimeException;

/**
 * Assembles the server instructions for a profile.
 *
 * Server instructions load at connection and stay resident for the whole
 * session, so every line is a permanent context tax on every conversation.
 * Fragments are therefore included only when the profile actually needs them:
 * a profile that does not pseudonymize does not pay for the pseudonymization
 * rules, and one without the telescope tool does not carry its workflow.
 *
 * Application instructions are appended to the package's, not substituted for
 * them. The reference implementation replaced the whole block, which meant an
 * application describing its own schema silently dropped the rules explaining
 * what a pseudonym is — and an agent that does not know [email:a3f9] is a
 * pseudonym draws confident, wrong conclusions.
 */
class InstructionComposer
{
    public function compose(Profile $profile): string
    {
        $parts = [
            $this->fragment('core'),
            // Always included. When two of these servers are connected the
            // model picks between identical tool names, and a question about
            // real usage answered from staging produces a plausible falsehood.
            str_replace('{{ source }}', $profile->sourceDescription(), $this->fragment('source')),
        ];

        if ($profile->anonymize) {
            $parts[] = $this->fragment('pseudonymization');
        }

        if ($profile->exposes('telescope')) {
            $parts[] = $this->fragment('telescope');
        }

        $parts[] = $this->applicationInstructions($profile);

        return implode("\n\n", array_filter($parts, static fn (string $part) => trim($part) !== ''));
    }

    protected function fragment(string $name): string
    {
        $path = __DIR__.'/../../resources/instructions/'.$name.'.md';

        return trim((string) file_get_contents($path));
    }

    /**
     * Read the application's own instructions, if the profile names a file.
     *
     * A configured-but-missing file throws rather than degrading quietly. The
     * failure mode of ignoring it is an agent that answers domain questions
     * without the enums and conventions it needed, and gets them subtly wrong
     * with no sign anything is missing.
     */
    protected function applicationInstructions(Profile $profile): string
    {
        if ($profile->instructions === null) {
            return '';
        }

        if (! is_file($profile->instructions)) {
            throw new RuntimeException(
                "Profile [{$profile->name}] points at an instructions file that does not exist: "
                ."{$profile->instructions}"
            );
        }

        return trim((string) file_get_contents($profile->instructions));
    }
}
