<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One-shot installer for the Slack channel. Idempotent — re-running it
 * after partial completion is safe; each step detects current state
 * and skips work that's already been done.
 *
 * What it automates:
 *   - Verifies the host has `laravel/slack-notification-channel` in composer.json
 *   - Copies the stubs/add_slack_user_id_to_users.php.stub migration into the
 *     host's database/migrations with a fresh timestamp (unless the column
 *     or a similarly-named migration already exists)
 *   - Patches config/notifications-max.php to activate the slack channel
 *     block with `richness => 'markdown'` and `route_via => 'slack_user_id'`,
 *     and adds 'slack' to `type_defaults.allowed_channels`
 *   - Patches config/services.php to declare the `slack.notifications`
 *     block (token + default channel keys)
 *   - Appends `SLACK_BOT_USER_OAUTH_TOKEN=` to .env and .env.example
 *     when the key is absent
 *
 * What it does NOT touch:
 *   - The User model. Replacing `use Notifiable;` with `use NotifiableViaMax;`
 *     is base package setup, not slack-specific — surfaced in next-steps
 *     output if not yet done.
 *   - The .env value of the token itself (the operator pastes their own).
 *   - composer install / require (the operator runs it; CI-unfriendly to
 *     auto-fire a network-bound composer command from artisan).
 *   - php artisan migrate (operator runs it; explicit by design).
 *
 * Anything the command cannot safely auto-patch (e.g. the host's config
 * has been refactored beyond regex recognition) is printed as a snippet
 * in the final summary so nothing silently breaks.
 */
class InstallSlackCommand extends Command
{
    protected $signature = 'notifications-max:install-slack';

    protected $description = 'Set up the Slack channel: migration, config patches, env scaffolding, and a checklist of remaining manual steps.';

    protected Filesystem $files;

    /** @var array<int, string>  Lines accumulated for the next-steps printout. */
    protected array $nextSteps = [];

    /** @var array<int, string>  Lines accumulated for the "done" printout. */
    protected array $done = [];

    /** @var array<int, string>  Lines accumulated for the "skipped" printout (already configured). */
    protected array $skipped = [];

    public function handle(Filesystem $files): int
    {
        $this->files = $files;

        $this->components->info('Installing Slack channel for notifications-max…');

        $this->checkComposerDependency();
        $this->createMigration();
        $this->patchNotificationsMaxConfig();
        $this->patchServicesConfig();
        $this->appendEnvVars();
        $this->checkUserModelTrait();
        $this->addPostInstallSteps();

        $this->printSummary();

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------------
    // composer dep
    // ---------------------------------------------------------------------

    protected function checkComposerDependency(): void
    {
        $composerPath = base_path('composer.json');

        if (! $this->files->exists($composerPath)) {
            $this->nextSteps[] = 'Could not read composer.json — install <fg=cyan>laravel/slack-notification-channel</> manually.';

            return;
        }

        $composer = json_decode((string) $this->files->get($composerPath), true) ?: [];
        $require = (array) ($composer['require'] ?? []);

        if (isset($require['laravel/slack-notification-channel'])) {
            $this->skipped[] = 'laravel/slack-notification-channel already in composer.json';

            return;
        }

        $this->nextSteps[] = 'Run <fg=cyan>composer require laravel/slack-notification-channel</> to install the Slack notification channel.';
    }

    // ---------------------------------------------------------------------
    // migration
    // ---------------------------------------------------------------------

    protected function createMigration(): void
    {
        // Column already on the users table — nothing to migrate.
        try {
            if (Schema::hasColumn('users', 'slack_user_id')) {
                $this->skipped[] = 'users.slack_user_id column already exists';

                return;
            }
        } catch (Throwable) {
            // DB unreachable (fresh install, no .env DB config yet). Fall
            // through to the filename-based check so we still avoid
            // duplicating an existing un-migrated stub.
        }

        $migrationsDir = database_path('migrations');

        if (! $this->files->isDirectory($migrationsDir)) {
            $this->files->makeDirectory($migrationsDir, 0755, true);
        }

        // Filename-based de-dup — if any *_add_slack_user_id_to_users*.php
        // file is already there (from a prior run, or hand-crafted), skip.
        $existing = collect($this->files->files($migrationsDir))
            ->first(fn ($file) => str_contains($file->getFilename(), 'add_slack_user_id_to_users'));

        if ($existing !== null) {
            $this->skipped[] = "Migration already present: {$existing->getFilename()}";

            return;
        }

        $stub = __DIR__ . '/../../stubs/add_slack_user_id_to_users.php.stub';

        if (! $this->files->exists($stub)) {
            $this->components->error("Migration stub missing: {$stub}");

            return;
        }

        $timestamp = now()->format('Y_m_d_His');
        $target = "{$migrationsDir}/{$timestamp}_add_slack_user_id_to_users.php";

        $this->files->copy($stub, $target);

        $this->done[] = "Created migration: {$timestamp}_add_slack_user_id_to_users.php";
        $this->nextSteps[] = 'Run <fg=cyan>php artisan migrate</> to add the slack_user_id column.';
    }

    // ---------------------------------------------------------------------
    // config/notifications-max.php
    // ---------------------------------------------------------------------

    protected function patchNotificationsMaxConfig(): void
    {
        $path = config_path('notifications-max.php');

        if (! $this->files->exists($path)) {
            $this->nextSteps[] = 'Publish the package config first: <fg=cyan>php artisan vendor:publish --tag=filament-notifications-max-config</>';

            return;
        }

        $contents = (string) $this->files->get($path);
        $original = $contents;

        $contents = $this->ensureSlackChannelBlock($contents);
        $contents = $this->ensureSlackInAllowedChannels($contents);

        if ($contents === $original) {
            $this->skipped[] = 'config/notifications-max.php already has the slack channel configured';

            return;
        }

        $this->writeWithBackup($path, $contents);
        $this->done[] = 'Patched config/notifications-max.php';
    }

    /**
     * Ensure the `channels` array has an active `slack` entry carrying
     * the keys this package's SlackChannelHandler reads (`richness` and
     * `route_via`).
     *
     * Detection uses bracket-walking rather than a lazy-quantifier regex
     * because a host's slack block may contain nested arrays like
     * `'physical' => ['slack']` — a lazy `[\s\S]*?\],` matches the first
     * inline `],` rather than the block's own closing `],`, splicing the
     * file in half.
     */
    protected function ensureSlackChannelBlock(string $contents): string
    {
        // Find the `'slack' =>` start IF it's inside the `channels` array
        // and IF it's not commented out.
        $activeOffset = $this->findActiveSlackBlockOffset($contents);

        if ($activeOffset !== null) {
            return $this->injectKeysIntoSlackBlock($contents, $activeOffset);
        }

        // Commented-out shipped example? Strip the leading `// ` from
        // each line of the block, then inject the required keys.
        $contents = $this->uncommentShippedSlackBlock($contents);

        $activeOffset = $this->findActiveSlackBlockOffset($contents);

        if ($activeOffset !== null) {
            return $this->injectKeysIntoSlackBlock($contents, $activeOffset);
        }

        // No slack block at all — append a fresh entry to the channels array.
        return $this->appendSlackBlockToChannels($contents);
    }

    /**
     * Locate the start of an uncommented `'slack' => [` inside the
     * `channels` array. Returns the byte offset of the apostrophe before
     * `slack`, or null if no active block exists in scope.
     */
    protected function findActiveSlackBlockOffset(string $contents): ?int
    {
        $channelsOpener = $this->findArrayOpenerOffset($contents, 'channels');

        if ($channelsOpener === null) {
            return null;
        }

        $channelsCloser = $this->findMatchingBracket($contents, $channelsOpener);

        if ($channelsCloser === null) {
            return null;
        }

        $cursor = $channelsOpener;

        while ($cursor < $channelsCloser) {
            $pos = strpos($contents, "'slack'", $cursor);

            if ($pos === false || $pos >= $channelsCloser) {
                return null;
            }

            // Skip occurrences that are commented out — walk backwards to
            // the line start and look for a leading `//` before any non-
            // whitespace content.
            $lineStart = strrpos(substr($contents, 0, $pos), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $linePrefix = substr($contents, $lineStart, $pos - $lineStart);

            if (! preg_match('/^\s*\/\//', $linePrefix)) {
                // Confirm the `=>` shape follows — guard against random
                // string occurrences (e.g. `route_via => 'slack_user_id'`
                // contains `'slack'` lexically once we add it).
                $after = substr($contents, $pos + strlen("'slack'"), 10);

                if (preg_match('/^\s*=>\s*\[/', $after)) {
                    return $pos;
                }
            }

            $cursor = $pos + strlen("'slack'");
        }

        return null;
    }

    /**
     * Strip the leading `// ` from each line of the shipped commented
     * slack example. Looks for `// 'slack' => [` and walks forward
     * counting commented `[`/`]` until balanced.
     */
    protected function uncommentShippedSlackBlock(string $contents): string
    {
        if (! preg_match("/^[ \t]*\/\/[ \t]*'slack'\s*=>\s*\[/m", $contents, $match, PREG_OFFSET_CAPTURE)) {
            return $contents;
        }

        $blockStart = $match[0][1];
        $lines = preg_split('/(?<=\n)/', substr($contents, $blockStart)) ?: [];
        $consumed = 0;
        $depth = 0;
        $taken = [];

        foreach ($lines as $line) {
            $taken[] = $line;
            $consumed += strlen($line);

            // Only count brackets on commented lines — once we leave the
            // commented run we've over-shot and should stop.
            if (! preg_match('/^\s*\/\//', $line)) {
                break;
            }

            $stripped = preg_replace('/[\'"][^\'"]*[\'"]/', '', $line);
            $depth += substr_count((string) $stripped, '[');
            $depth -= substr_count((string) $stripped, ']');

            if ($depth === 0) {
                break;
            }
        }

        $rawBlock = implode('', $taken);
        $uncommented = (string) preg_replace("/^([ \t]*)\/\/[ \t]?/m", '$1', $rawBlock);

        return substr($contents, 0, $blockStart)
            . $uncommented
            . substr($contents, $blockStart + $consumed);
    }

    /**
     * Given the offset of `'slack'` in an active block, find its
     * array's opening `[`, walk to its matching `]`, and insert any
     * missing required keys just before the closing `]`.
     */
    protected function injectKeysIntoSlackBlock(string $contents, int $slackKeyOffset): string
    {
        $openerOffset = strpos($contents, '[', $slackKeyOffset);

        if ($openerOffset === false) {
            return $contents;
        }

        $closerOffset = $this->findMatchingBracket($contents, $openerOffset);

        if ($closerOffset === null) {
            return $contents;
        }

        $body = substr($contents, $openerOffset + 1, $closerOffset - $openerOffset - 1);

        $missing = [];

        if (! preg_match("/'richness'\s*=>/", $body)) {
            $missing[] = "'richness' => 'markdown'";
        }

        if (! preg_match("/'route_via'\s*=>/", $body)) {
            $missing[] = "'route_via' => 'slack_user_id'";
        }

        if ($missing === []) {
            return $contents;
        }

        // Infer the inner indent from the first non-blank line of the
        // body so the injected lines visually match.
        $innerIndent = '    ';

        if (preg_match("/\n([ \t]+)\S/", $body, $indentMatch)) {
            $innerIndent = $indentMatch[1];
        }

        $insertion = '';
        foreach ($missing as $line) {
            $insertion .= "{$innerIndent}{$line},\n";
        }

        // Find the indent that closes the block (the whitespace on the
        // line with the `]`) so we re-emit it after our insertion.
        $closeLineStart = strrpos(substr($contents, 0, $closerOffset), "\n");
        $closeLineStart = $closeLineStart === false ? 0 : $closeLineStart + 1;
        $closeIndent = substr($contents, $closeLineStart, $closerOffset - $closeLineStart);

        // The content up to $closeLineStart already ends with the `\n`
        // that terminated the previous body line — no prefix newline
        // needed. The insertion's own lines each terminate with `\n`,
        // leaving the closing `]` right after $closeIndent.
        return substr($contents, 0, $closeLineStart)
            . $insertion
            . $closeIndent
            . substr($contents, $closerOffset);
    }

    /**
     * Last-resort append: drop a fresh slack block before the closing
     * `]` of the `channels` array. Matches the array opener and walks
     * forward through balanced brackets.
     */
    protected function appendSlackBlockToChannels(string $contents): string
    {
        $openerPos = $this->findArrayOpenerOffset($contents, 'channels');

        if ($openerPos === null) {
            $this->nextSteps[] = $this->snippetForSlackChannelBlock();

            return $contents;
        }

        $closerPos = $this->findMatchingBracket($contents, $openerPos);

        if ($closerPos === null) {
            $this->nextSteps[] = $this->snippetForSlackChannelBlock();

            return $contents;
        }

        $indent = '        ';
        $insert = "{$indent}'slack' => [\n"
            . "{$indent}    'label' => 'Slack',\n"
            . "{$indent}    'physical' => ['slack'],\n"
            . "{$indent}    'richness' => 'markdown',\n"
            . "{$indent}    'route_via' => 'slack_user_id',\n"
            . "{$indent}    'content_fields' => ['body' => 'text'],\n"
            . "{$indent}],\n    ";

        return substr($contents, 0, $closerPos) . $insert . substr($contents, $closerPos);
    }

    /**
     * Find the `[` that opens the array assigned to the named key at
     * the current scope depth (e.g. `'channels' => [`). Returns the
     * byte offset of the `[`, or null if not found.
     */
    protected function findArrayOpenerOffset(string $contents, string $key): ?int
    {
        if (! preg_match("/'" . preg_quote($key, '/') . "'\s*=>\s*\[/", $contents, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        // Offset of the `[` is the match offset + match length - 1.
        return $match[0][1] + strlen($match[0][0]) - 1;
    }

    /**
     * Given the offset of an opening `[`, walk forward tracking nesting
     * to find the matching `]`. Skips characters inside string literals
     * (single- and double-quoted, with backslash escapes) so a `[` or
     * `]` inside a content field doesn't confuse the counter.
     */
    protected function findMatchingBracket(string $contents, int $openerOffset): ?int
    {
        $depth = 0;
        $len = strlen($contents);
        $inString = false;
        $stringChar = '';

        for ($i = $openerOffset; $i < $len; $i++) {
            $ch = $contents[$i];

            if ($inString) {
                if ($ch === '\\') {
                    $i++;  // skip escaped next char

                    continue;
                }

                if ($ch === $stringChar) {
                    $inString = false;
                }

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString = true;
                $stringChar = $ch;

                continue;
            }

            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Add `'slack'` to `type_defaults.allowed_channels` if not present.
     * Tolerant of multi-line arrays and single-line `['push', 'email']`.
     */
    protected function ensureSlackInAllowedChannels(string $contents): string
    {
        return (string) preg_replace_callback(
            "/('allowed_channels'\s*=>\s*\[)([^\]]*)(\])/",
            function (array $m): string {
                if (str_contains($m[2], "'slack'")) {
                    return $m[0];
                }

                $items = $m[2];
                $trimmed = rtrim($items);
                $hadTrailingComma = str_ends_with($trimmed, ',');
                $separator = $hadTrailingComma ? ' ' : ', ';

                return $m[1] . $trimmed . $separator . "'slack'" . substr($items, strlen($trimmed)) . $m[3];
            },
            $contents,
            1,
        );
    }

    protected function snippetForSlackChannelBlock(): string
    {
        return "Add this entry to <fg=cyan>config/notifications-max.php</> under `channels`:\n"
            . "    'slack' => [\n"
            . "        'label' => 'Slack',\n"
            . "        'physical' => ['slack'],\n"
            . "        'richness' => 'markdown',\n"
            . "        'route_via' => 'slack_user_id',\n"
            . "        'content_fields' => ['body' => 'text'],\n"
            . '    ],';
    }

    // ---------------------------------------------------------------------
    // config/services.php
    // ---------------------------------------------------------------------

    protected function patchServicesConfig(): void
    {
        $path = config_path('services.php');

        if (! $this->files->exists($path)) {
            $this->nextSteps[] = 'config/services.php missing — create it with a slack.notifications block (see Laravel docs).';

            return;
        }

        $contents = (string) $this->files->get($path);

        if (preg_match("/'slack'\s*=>\s*\[[\s\S]*?'notifications'\s*=>\s*\[/", $contents)) {
            $this->skipped[] = 'config/services.php already declares slack.notifications';

            return;
        }

        // Insert before the final `];` of the returned array.
        $block = "    'slack' => [\n"
            . "        'notifications' => [\n"
            . "            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),\n"
            . "            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),\n"
            . "        ],\n"
            . "    ],\n\n";

        $patched = preg_replace('/\n\];\s*$/', "\n\n{$block}];\n", $contents, 1);

        if ($patched === null || $patched === $contents) {
            $this->nextSteps[] = "Add this to <fg=cyan>config/services.php</>:\n{$block}";

            return;
        }

        $this->writeWithBackup($path, $patched);
        $this->done[] = 'Patched config/services.php';
    }

    // ---------------------------------------------------------------------
    // .env / .env.example
    // ---------------------------------------------------------------------

    protected function appendEnvVars(): void
    {
        foreach (['.env', '.env.example'] as $name) {
            $path = base_path($name);

            if (! $this->files->exists($path)) {
                continue;
            }

            $contents = (string) $this->files->get($path);
            $original = $contents;

            if (! preg_match('/^SLACK_BOT_USER_OAUTH_TOKEN=/m', $contents)) {
                $contents = rtrim($contents, "\n") . "\n\nSLACK_BOT_USER_OAUTH_TOKEN=\n";
            }

            if (! preg_match('/^NOTIFICATIONS_MAX_SLACK_AUTO_RESOLVE=/m', $contents)) {
                $contents = rtrim($contents, "\n") . "\nNOTIFICATIONS_MAX_SLACK_AUTO_RESOLVE=true\n";
            }

            if ($contents === $original) {
                $this->skipped[] = "{$name} already has Slack env keys";

                continue;
            }

            $this->files->put($path, $contents);
            $this->done[] = "Updated {$name}";
        }
    }

    // ---------------------------------------------------------------------
    // user model
    // ---------------------------------------------------------------------

    protected function checkUserModelTrait(): void
    {
        $modelClass = config('auth.providers.users.model');

        if (! is_string($modelClass) || ! class_exists($modelClass)) {
            $this->nextSteps[] = 'Could not locate User model from auth config. Replace `use Notifiable;` with `use NotifiableViaMax;` in your notifiable model (see package README).';

            return;
        }

        try {
            $reflection = new \ReflectionClass($modelClass);
            $file = $reflection->getFileName();
        } catch (Throwable) {
            $file = false;
        }

        if (! is_string($file) || ! $this->files->exists($file)) {
            $this->nextSteps[] = "Replace `use Notifiable;` with `use NotifiableViaMax;` in your {$modelClass} (see package README).";

            return;
        }

        $contents = (string) $this->files->get($file);

        if (str_contains($contents, 'NotifiableViaMax')) {
            $this->skipped[] = 'User model already uses NotifiableViaMax';

            return;
        }

        $this->nextSteps[] = "In <fg=cyan>{$file}</>, replace:\n"
            . "    use Illuminate\\Notifications\\Notifiable;\n"
            . "    ...\n"
            . "    use Notifiable;\n"
            . "with:\n"
            . "    use Devletes\\NotificationsMax\\Concerns\\NotifiableViaMax;\n"
            . "    ...\n"
            . '    use NotifiableViaMax;';
    }

    // ---------------------------------------------------------------------
    // post-install
    // ---------------------------------------------------------------------

    protected function addPostInstallSteps(): void
    {
        // Token — only flag if missing or placeholder-shaped.
        $token = env('SLACK_BOT_USER_OAUTH_TOKEN', '');
        if (! is_string($token) || trim($token) === '') {
            $this->nextSteps[] = 'Set <fg=cyan>SLACK_BOT_USER_OAUTH_TOKEN</> in your .env to a bot token with scopes: '
                . '<fg=yellow>users:read.email</>, <fg=yellow>chat:write</>, <fg=yellow>im:write</>.';
        }

        $this->nextSteps[] = 'Backfill Slack IDs for existing users: <fg=cyan>php artisan notifications-max:sync-slack-user-ids</>';
    }

    // ---------------------------------------------------------------------
    // output
    // ---------------------------------------------------------------------

    protected function printSummary(): void
    {
        $this->newLine();

        if ($this->done !== []) {
            $this->components->success('Done');
            foreach ($this->done as $line) {
                $this->line("  <fg=green>✓</> {$line}");
            }
            $this->newLine();
        }

        if ($this->skipped !== []) {
            $this->components->info('Already configured');
            foreach ($this->skipped as $line) {
                $this->line("  <fg=gray>·</> {$line}");
            }
            $this->newLine();
        }

        if ($this->nextSteps !== []) {
            $this->components->warn('Next steps');
            foreach ($this->nextSteps as $i => $line) {
                $num = $i + 1;
                $this->line("  <fg=yellow>{$num}.</> {$line}");
            }
            $this->newLine();
        } else {
            $this->components->success('Slack channel is fully configured. Send a test notification to verify.');
        }
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------

    /**
     * Write `$contents` to `$path`, first copying the existing file to
     * `<path>.bak` so a misjudged regex patch is recoverable in one step.
     * Backup is overwritten on each run — fine, the file in source
     * control is the real safety net.
     */
    protected function writeWithBackup(string $path, string $contents): void
    {
        if ($this->files->exists($path)) {
            $this->files->copy($path, "{$path}.bak");
        }

        $this->files->put($path, $contents);
    }
}
