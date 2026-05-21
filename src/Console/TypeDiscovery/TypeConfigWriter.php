<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Console\TypeDiscovery;

use Devletes\NotificationsMax\Console\InstallSlackCommand;
use Illuminate\Filesystem\Filesystem;

/**
 * Append generated type entries to the host's notification-types config
 * file. Merge-not-overwrite by default — existing keys are preserved
 * verbatim so hand-tuned titles, target panels, and channel lists survive
 * re-runs of the generate command.
 *
 * The writer takes a map of `typeKey => preformatted PHP source` rather
 * than synthesising the source itself; the generate command is the right
 * place to make the "what should the entry look like" decisions, and
 * keeping that out of the writer makes the file-manipulation logic easy
 * to unit-test in isolation.
 *
 * Two file shapes are supported:
 *
 *   1. File doesn't exist  → create a fresh `<?php return [ <entries> ];`
 *      scaffold. Common on first run when the host hasn't created their
 *      notifications.php yet.
 *
 *   2. File exists         → insert entries before the closing `];` of
 *      the outermost `return [ … ];`. We use the LAST `];` in the file
 *      to identify it, which is correct for standard Laravel config
 *      files (single returned array at the top level).
 *
 * Files written are backed up to `<path>.bak` first — a guard against
 * regex misjudgements on weirdly-formatted host configs, matching the
 * pattern set by {@see InstallSlackCommand}.
 */
class TypeConfigWriter
{
    public function __construct(protected Filesystem $files) {}

    /**
     * @param  array<string, string>  $entries  type key → preformatted PHP source for the entry (starts with `'key' => […],` and may span multiple lines)
     */
    public function write(string $configPath, array $entries, bool $force = false): WriteResult
    {
        $exists = $this->files->exists($configPath);
        $existingKeys = $exists ? $this->loadExistingKeys($configPath) : [];

        $toWrite = [];
        $skipped = [];

        foreach ($entries as $key => $source) {
            if (! $force && in_array($key, $existingKeys, true)) {
                $skipped[] = $key;

                continue;
            }

            $toWrite[$key] = $source;
        }

        if ($toWrite === []) {
            return new WriteResult(addedKeys: [], skippedKeys: $skipped, wasCreated: false, wasWritten: false);
        }

        if (! $exists) {
            $this->files->put($configPath, $this->scaffoldNewFile($toWrite));

            return new WriteResult(
                addedKeys: array_keys($toWrite),
                skippedKeys: $skipped,
                wasCreated: true,
                wasWritten: true,
            );
        }

        $original = (string) $this->files->get($configPath);
        $updated = $this->insertBeforeFinalArrayClose($original, $toWrite);

        if ($updated === null) {
            return new WriteResult(
                addedKeys: [],
                skippedKeys: $skipped,
                wasCreated: false,
                wasWritten: false,
                couldNotPatch: true,
            );
        }

        $this->files->copy($configPath, $configPath.'.bak');
        $this->files->put($configPath, $updated);

        return new WriteResult(
            addedKeys: array_keys($toWrite),
            skippedKeys: $skipped,
            wasCreated: false,
            wasWritten: true,
        );
    }

    /**
     * Build the snippet a `--print-only` invocation hands back to the dev
     * to paste manually. Same formatting as a write but wrapped in a
     * leading hint comment so it's clear what to do with it.
     *
     * @param  array<string, string>  $entries
     */
    public function preview(array $entries): string
    {
        if ($entries === []) {
            return '';
        }

        return $this->indentEntries($entries);
    }

    /**
     * Load the top-level array keys of the host's types config without
     * caring about the per-entry shape.
     *
     * @return array<int, string>
     */
    protected function loadExistingKeys(string $configPath): array
    {
        try {
            $loaded = require $configPath;
        } catch (\Throwable) {
            // A config file that throws during require is a host bug
            // beyond our reach. Pretend it had no keys — every discovered
            // type will be flagged as new, the writer will refuse to
            // patch (because findFinalArrayClose can't make sense of it
            // either), and the command surfaces the failure.
            return [];
        }

        if (! is_array($loaded)) {
            return [];
        }

        return array_values(array_filter(
            array_keys($loaded),
            fn ($key): bool => is_string($key),
        ));
    }

    /**
     * @param  array<string, string>  $entries
     */
    protected function scaffoldNewFile(array $entries): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            /*
            |--------------------------------------------------------------------------
            | Notification Type Registry
            |--------------------------------------------------------------------------
            |
            | Generated by `php artisan notifications-max:generate-types`. Each entry
            | is keyed by the type string passed to NotificationDispatcher::send().
            | Re-run the command to add newly-discovered types; existing entries
            | are preserved.
            |
            */

            return [
            {$this->indentEntries($entries)}
            ];

            PHP;
    }

    /**
     * @param  array<string, string>  $entries
     */
    protected function indentEntries(array $entries): string
    {
        // The entry strings already carry per-line indentation that
        // matches a 4-space outer indent. Concatenate with one blank line
        // between entries for readability.
        return implode("\n\n", $entries);
    }

    /**
     * @param  array<string, string>  $entries
     */
    protected function insertBeforeFinalArrayClose(string $contents, array $entries): ?string
    {
        // Find the last `];` in the file — by convention the close of the
        // outermost `return [ … ];`. preg_match with PREG_OFFSET_CAPTURE
        // on `\][\s]*;` gives us the position; we scan from the end.
        if (! preg_match_all('/\][\s]*;/', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $lastMatch = end($matches[0]);
        $closeBracketOffset = (int) $lastMatch[1];

        // Walk back from `]` to find the previous non-whitespace byte. If
        // the previous content already ends with a trailing comma the
        // formatting is clean; if not we add one to keep the file valid.
        $cursor = $closeBracketOffset - 1;

        while ($cursor >= 0 && in_array($contents[$cursor], [' ', "\t", "\r", "\n"], true)) {
            $cursor--;
        }

        $needsLeadingComma = $cursor >= 0 && $contents[$cursor] !== ',' && $contents[$cursor] !== '[';

        $insertion = ($needsLeadingComma ? ",\n\n" : "\n")
            .$this->indentEntries($entries)
            ."\n";

        return substr($contents, 0, $closeBracketOffset)
            .$insertion
            .substr($contents, $closeBracketOffset);
    }
}
