<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Console;

use Afiqsazlan\SafeSql\Anonymization\AnonymizerFactory;
use Afiqsazlan\SafeSql\Contracts\Anonymizer;
use Afiqsazlan\SafeSql\Profiles\Profile;
use Afiqsazlan\SafeSql\Schema\ColumnClassifier;
use Afiqsazlan\SafeSql\Schema\ColumnSuggestion;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Generates a starter classification config by inspecting a real schema.
 *
 * Fail-closed pseudonymization means every unclassified column comes back as
 * a token. On a mature schema that is most of them, and a first run that
 * tokenizes two thirds of the database reads as broken rather than safe. This
 * command turns that first hour of manual classification into one review pass.
 *
 * It prints classifications, never data. Sampled values are inspected in
 * memory and discarded — a tool that echoed PII to a terminal would undermine
 * the package it configures.
 */
class ClassifyColumnsCommand extends Command
{
    protected $signature = 'safe-sql:classify
        {--profile=research : Which configured profile to inspect}
        {--samples=50 : Rows to sample per column when detecting value shapes}
        {--no-sample : Classify from column names only, without reading any data}
        {--write= : Write the generated config to this path instead of printing it}';

    protected $description = 'Inspect a database and suggest safe-sql column classifications';

    public function handle(DatabaseManager $database, AnonymizerFactory $anonymizers): int
    {
        try {
            $profile = Profile::make((string) $this->option('profile'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $schema = $database->connection($profile->connection)->getSchemaBuilder();
        $classifier = new ColumnClassifier;

        // Probing the real anonymizer avoids reimplementing its decision order
        // here, where the two could silently drift apart.
        $anonymizer = $anonymizers->make(
            new Profile($profile->name, $profile->connection, true, $profile->tools, null)
        );

        /** @var array<int, string> $excluded */
        $excluded = Config::get('safe-sql.schema.excluded_tables', []);

        $pii = [];
        $safe = [];
        $review = [];
        $contradictions = [];
        $alreadyHandled = 0;
        $columnCount = 0;

        foreach ($schema->getTables() as $table) {
            $name = $table['name'] ?? null;

            if (! is_string($name) || in_array($name, $excluded, true)) {
                continue;
            }

            foreach ($schema->getColumns($name) as $column) {
                $label = (string) $column['name'];
                $columnCount++;

                // A column someone has explicitly classified is settled.
                if ($this->explicitlyClassified($label)) {
                    $alreadyHandled++;

                    continue;
                }

                $samples = $this->option('no-sample')
                    ? []
                    : $this->sample($database, $profile, $name, $label);

                $suggestion = $classifier->classify(
                    $label,
                    is_string($column['type'] ?? null) ? $column['type'] : null,
                    $samples,
                );

                // Columns passing raw on a safe *pattern* rather than an
                // explicit entry are audited rather than trusted. The pattern
                // is a convention, and conventions are wrong sometimes —
                // "/_id$/" happily passes a column holding phone numbers.
                if ($this->passesRaw($anonymizer, $label)) {
                    if ($suggestion->bucket === ColumnSuggestion::BUCKET_PII) {
                        $contradictions[$label] = $suggestion;
                        $pii[$label] = $suggestion;
                    } else {
                        $alreadyHandled++;
                    }

                    continue;
                }

                match ($suggestion->bucket) {
                    ColumnSuggestion::BUCKET_PII => $pii[$label] = $suggestion,
                    ColumnSuggestion::BUCKET_SAFE => $safe[$label] = $suggestion,
                    default => $review[$label] = $suggestion,
                };
            }
        }

        $this->report($columnCount, $alreadyHandled, $pii, $safe, $review, $contradictions);

        $config = $this->render($pii, $safe, $review);

        if (($path = $this->option('write')) !== null) {
            file_put_contents((string) $path, $config);
            $this->newLine();
            $this->info("Written to {$path}");

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($config);

        return self::SUCCESS;
    }

    /**
     * Whether a human has already made a decision about this column, as
     * opposed to a convention having made one for them.
     */
    protected function explicitlyClassified(string $label): bool
    {
        /** @var array<string, string> $pii */
        $pii = Config::get('safe-sql.anonymizer.pii_columns', []);
        /** @var array<int, string> $safe */
        $safe = Config::get('safe-sql.anonymizer.safe_columns', []);

        return array_key_exists($label, array_change_key_case($pii))
            || in_array($label, array_map(strtolower(...), $safe), true);
    }

    protected function passesRaw(object $anonymizer, string $label): bool
    {
        /** @var Anonymizer $anonymizer */
        return $anonymizer->anonymizeValue($label, 'zz-probe-zz') === 'zz-probe-zz';
    }

    /**
     * @return array<int, string>
     */
    protected function sample(DatabaseManager $database, Profile $profile, string $table, string $column): array
    {
        try {
            $rows = $database->connection($profile->connection)
                ->table($table)
                ->whereNotNull($column)
                ->limit((int) $this->option('samples'))
                ->pluck($column)
                ->all();
        } catch (Throwable) {
            // A column we cannot read is simply a column we cannot advise on.
            return [];
        }

        return array_values(array_map(
            static fn ($value) => is_scalar($value) ? (string) $value : '',
            $rows
        ));
    }

    /**
     * @param  array<string, ColumnSuggestion>  $pii
     * @param  array<string, ColumnSuggestion>  $safe
     * @param  array<string, ColumnSuggestion>  $review
     * @param  array<string, ColumnSuggestion>  $contradictions
     */
    protected function report(int $total, int $handled, array $pii, array $safe, array $review, array $contradictions = []): void
    {
        $this->newLine();
        $this->line("  Columns inspected      {$total}");
        $this->line('  Already classified     '.$handled);
        $this->line('  Suggested as PII       '.count($pii));
        $this->line('  Suggested as safe      '.count($safe));
        $this->line('  Needs your judgement   '.count($review));

        if ($contradictions !== []) {
            $this->newLine();
            $this->error('  Currently returned RAW but look like PII — fix these first:');

            foreach ($contradictions as $suggestion) {
                $this->line("    {$suggestion->column}  ({$suggestion->reason})");
            }
        }

        $sampled = array_filter(
            $pii,
            static fn (ColumnSuggestion $s) => $s->fromSampledData && ! isset($contradictions[$s->column])
        );

        if ($sampled !== []) {
            $this->newLine();
            $this->warn('  Found by reading data, not by name:');

            foreach ($sampled as $suggestion) {
                $this->line("    {$suggestion->column}  ({$suggestion->reason})");
            }
        }
    }

    /**
     * @param  array<string, ColumnSuggestion>  $pii
     * @param  array<string, ColumnSuggestion>  $safe
     * @param  array<string, ColumnSuggestion>  $review
     */
    protected function render(array $pii, array $safe, array $review): string
    {
        $out = "<?php\n\n"
            ."/*\n"
            ." * Generated by safe-sql:classify. Suggestions, not decisions —\n"
            ." * review before use. Every safe_columns entry is a choice to return\n"
            ." * real values, so read that list twice.\n"
            ." */\n\n"
            ."return [\n    'anonymizer' => [\n\n        'pii_columns' => [\n";

        foreach ($pii as $label => $s) {
            $marker = $s->fromSampledData ? '  // FROM DATA: ' : '  // ';
            $out .= sprintf("            '%s' => '%s',%s%s\n", $label, $s->tokenType, $marker, $s->reason);
        }

        $out .= "        ],\n\n        'safe_columns' => [\n";

        foreach ($safe as $label => $s) {
            $out .= sprintf("            '%s', // %s\n", $label, $s->reason);
        }

        $out .= "        ],\n    ],\n];\n";

        if ($review !== []) {
            $out .= "\n/*\n * Unclassified — these stay pseudonymized until you decide.\n * Move each to pii_columns or safe_columns above.\n *\n";

            foreach (array_chunk(array_keys($review), 4) as $chunk) {
                $out .= ' * '.implode(', ', $chunk)."\n";
            }

            $out .= " */\n";
        }

        return $out;
    }
}
