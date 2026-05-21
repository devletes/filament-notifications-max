<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Console;

use Devletes\NotificationsMax\Console\TypeDiscovery\CallSiteScanner;
use Devletes\NotificationsMax\Console\TypeDiscovery\DiscoveredCallSite;
use Devletes\NotificationsMax\Console\TypeDiscovery\TypeConfigWriter;
use Devletes\NotificationsMax\Console\TypeDiscovery\WriteResult;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use PhpParser\ParserFactory;

use function Laravel\Prompts\note;

/**
 * Scan the host application for NotificationDispatcher::send() / ::schedule()
 * call sites and append a config entry for any type key the registry
 * doesn't already cover. Inspired by `php artisan shield:generate`, which
 * discovers Filament resources / pages / widgets and auto-generates
 * matching policies + permissions.
 *
 * Design choices worth knowing about:
 *
 *   - **Two-pass discovery.** Pass 1 walks `app/` (or the path the dev
 *     specifies) looking for files that mention `NotificationDispatcher`
 *     as a substring. Pass 2 parses only the survivors with nikic/php-parser.
 *     This shrinks the AST workload to the handful of services / observers
 *     that actually trigger notifications.
 *
 *   - **Merge by default.** Existing type keys are left untouched —
 *     hand-tuned titles, bodies, target panels, and channel allowlists
 *     survive re-runs. `--force` rewrites everything with freshly-inferred
 *     defaults, useful only when restarting from scratch.
 *
 *   - **Skipped-dynamic surface.** Calls like `$dispatcher->send($type, …)`
 *     can't be statically resolved — the type key is a variable. We don't
 *     pretend to handle them; instead we list each one in a "Couldn't
 *     auto-generate" block at the end of the run so the dev knows which
 *     keys still need manual registration. Shield handles its equivalent
 *     (resources without a model FQCN) the same way.
 *
 *   - **Parser is suggested, not required.** nikic/php-parser is gated
 *     at runtime so installs that never call this command don't pay the
 *     ~1MB dependency cost. The first run prints a single-line install
 *     hint.
 */
class GenerateTypesCommand extends Command
{
    protected $signature = 'notifications-max:generate-types
                            {--path= : Directory to scan (relative to base_path() or absolute); defaults to app/}
                            {--type= : Comma-separated list of type keys to generate (defaults to all discovered)}
                            {--exclude : Treat --type as a denylist instead of an allowlist}
                            {--force : Overwrite existing type entries with freshly-inferred defaults (default is merge-only)}
                            {--dry-run : Print what would be added without touching the file}
                            {--print-only : Print the generated snippet to stdout and exit; never writes the config file}';

    protected $description = 'Scan host code for NotificationDispatcher calls and auto-fill the notification type registry.';

    public function handle(CallSiteScanner $scanner, Filesystem $files): int
    {
        if (! class_exists(ParserFactory::class)) {
            $this->components->error('nikic/php-parser is required for this command.');
            $this->line('  Install it with:');
            $this->line('    <fg=cyan>composer require --dev nikic/php-parser</>');

            return self::FAILURE;
        }

        $scanPath = $this->resolveScanPath();

        if (! is_dir($scanPath)) {
            $this->components->error("Scan path does not exist: {$scanPath}");

            return self::FAILURE;
        }

        $this->components->info("Scanning {$scanPath} for NotificationDispatcher call sites…");

        $callSites = $scanner->scan($scanPath);

        if ($callSites === []) {
            note('No NotificationDispatcher::send() / ::schedule() calls found. Nothing to generate.');

            return self::SUCCESS;
        }

        [$resolved, $dynamic] = $this->partitionDiscoveries($callSites);

        $filteredKeys = $this->applyTypeFilter(array_keys($resolved));
        $resolved = array_intersect_key($resolved, array_flip($filteredKeys));

        if ($resolved === [] && $dynamic === []) {
            note('No resolvable type keys matched the filters.');

            return self::SUCCESS;
        }

        $entries = $this->synthesiseEntries($resolved, $scanPath);

        $configPath = $this->resolveConfigPath();

        if ($configPath === null) {
            $this->components->error('The configured types_config_key uses a nested path (e.g. "app-events.types"). Auto-write only supports flat config files. Re-run with --print-only and paste the snippet manually.');
            $this->printEntries($entries);

            return self::FAILURE;
        }

        if ($this->option('print-only')) {
            $this->printEntries($entries);
            $this->renderDynamicWarning($dynamic, $scanPath);

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info('Dry run — no files written.');
            $this->printEntries($entries);
            $this->renderDynamicWarning($dynamic, $scanPath);

            return self::SUCCESS;
        }

        $writer = new TypeConfigWriter($files);
        $result = $writer->write($configPath, $entries, force: (bool) $this->option('force'));

        $this->renderResult($result, $configPath);
        $this->renderDynamicWarning($dynamic, $scanPath);
        $this->renderSummary($callSites, $result);

        return self::SUCCESS;
    }

    protected function resolveScanPath(): string
    {
        $raw = (string) ($this->option('path') ?? 'app');

        if (Str::startsWith($raw, ['/', '\\']) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $raw)) {
            return $raw;
        }

        return rtrim(base_path($raw), '/\\');
    }

    /**
     * Resolve the absolute filesystem path of the host's types config file.
     * Returns null when `types_config_key` declares a nested key — we
     * don't support patching nested arrays yet (the writer's bracket walk
     * assumes the outer `return [ … ];` is the target). The command falls
     * back to print-only output in that case.
     */
    protected function resolveConfigPath(): ?string
    {
        $key = (string) config('notifications-max.types_config_key', 'notifications');

        if (str_contains($key, '.')) {
            return null;
        }

        return config_path("{$key}.php");
    }

    /**
     * Group call sites by type key. Calls with a dynamic (non-literal)
     * type key go into the second bucket so we can render them as
     * "couldn't auto-generate" warnings.
     *
     * @param  array<int, DiscoveredCallSite>  $callSites
     * @return array{0: array<string, array<int, DiscoveredCallSite>>, 1: array<int, DiscoveredCallSite>}
     */
    protected function partitionDiscoveries(array $callSites): array
    {
        $resolved = [];
        $dynamic = [];

        foreach ($callSites as $site) {
            if ($site->isDynamic()) {
                $dynamic[] = $site;

                continue;
            }

            $resolved[$site->typeKey][] = $site;
        }

        return [$resolved, $dynamic];
    }

    /**
     * Apply the --type / --exclude filters to the resolved key list.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    protected function applyTypeFilter(array $keys): array
    {
        $raw = $this->option('type');

        if (! is_string($raw) || trim($raw) === '') {
            return $keys;
        }

        $filter = array_map('trim', explode(',', $raw));
        $filter = array_values(array_filter($filter, fn (string $k): bool => $k !== ''));

        if ($this->option('exclude')) {
            return array_values(array_diff($keys, $filter));
        }

        return array_values(array_intersect($keys, $filter));
    }

    /**
     * Build a `'foo.bar' => [...]` source string for each resolved key,
     * synthesising the obvious fields and leaving everything else blank
     * (or at the package's type_defaults) for the dev to fine-tune.
     *
     * @param  array<string, array<int, DiscoveredCallSite>>  $resolved
     * @return array<string, string> type key → preformatted PHP source
     */
    protected function synthesiseEntries(array $resolved, string $scanPath): array
    {
        $entries = [];

        ksort($resolved);

        foreach ($resolved as $key => $sites) {
            $entries[$key] = $this->renderEntry($key, $sites, $scanPath);
        }

        return $entries;
    }

    /**
     * @param  array<int, DiscoveredCallSite>  $sites
     */
    protected function renderEntry(string $key, array $sites, string $scanPath): string
    {
        $category = Str::contains($key, '.') ? Str::before($key, '.') : 'general';
        $label = Str::headline(str_replace('.', ' ', $key));

        $contextKeys = collect($sites)
            ->flatMap(fn (DiscoveredCallSite $s): array => $s->contextKeys)
            ->unique()
            ->values()
            ->all();

        $sources = collect($sites)
            ->map(fn (DiscoveredCallSite $s): string => $this->relativeSource($s, $scanPath))
            ->unique()
            ->values()
            ->all();

        $lines = [];
        $lines[] = "    '{$key}' => [";
        $lines[] = "        'category' => '{$category}',";
        $lines[] = "        'label' => '{$label}',";

        if ($contextKeys !== []) {
            $lines[] = '        // Expected context: '.implode(', ', $contextKeys);
        }

        if (count($sources) === 1) {
            $lines[] = '        // Source: '.$sources[0];
        } else {
            $lines[] = '        // Sources:';
            foreach ($sources as $source) {
                $lines[] = '        //   - '.$source;
            }
        }

        $lines[] = "        'title' => '',";
        $lines[] = "        'body' => '',";
        $lines[] = "        'default_channels' => ['push'],";
        $lines[] = "        'allowed_channels' => ['push', 'email'],";
        $lines[] = '    ],';

        return implode("\n", $lines);
    }

    /**
     * Make a source location readable by stripping the host's base path
     * so output reads like `app/Services/TaskService.php:42` rather than
     * a full Windows-style absolute path.
     */
    protected function relativeSource(DiscoveredCallSite $site, string $scanPath): string
    {
        $base = rtrim(base_path(), '/\\');
        $file = str_replace('\\', '/', $site->sourceFile);
        $baseSlash = str_replace('\\', '/', $base);

        if ($base !== '' && str_starts_with($file, $baseSlash.'/')) {
            $file = substr($file, strlen($baseSlash) + 1);
        }

        return "{$file}:{$site->sourceLine}";
    }

    /**
     * @param  array<string, string>  $entries
     */
    protected function printEntries(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $this->newLine();
        $this->line('Add these entries to your notification types config:');
        $this->newLine();

        // Print line-by-line rather than dumping each entry as one multi-
        // line write. Each output write becomes one Symfony OutputInterface
        // call, which makes the output friendlier to test harness mocks
        // (which match expectations one-write-at-a-time) and to verbosity
        // filters that operate per line.
        foreach ($entries as $source) {
            foreach (explode("\n", $source) as $line) {
                $this->line($line);
            }
            $this->newLine();
        }
    }

    /**
     * @param  array<int, DiscoveredCallSite>  $dynamic
     */
    protected function renderDynamicWarning(array $dynamic, string $scanPath): void
    {
        if ($dynamic === []) {
            return;
        }

        $this->newLine();
        $this->components->warn("Skipped {$this->count($dynamic, 'call', 'calls')} with a non-literal type key. Register manually:");

        foreach ($dynamic as $site) {
            $this->line('  <fg=yellow>·</> '.$this->relativeSource($site, $scanPath));
        }
    }

    protected function renderResult(WriteResult $result, string $configPath): void
    {
        $this->newLine();

        if ($result->couldNotPatch) {
            $this->components->error("Could not locate the closing `];` in {$configPath}. Run with --print-only and paste the snippet manually.");

            return;
        }

        if ($result->wasCreated) {
            $this->components->success("Created {$configPath} with ".count($result->addedKeys).' new type(s).');
        } elseif ($result->wasWritten) {
            $this->components->success('Added '.count($result->addedKeys)." new type(s) to {$configPath}.");
        } else {
            $this->components->info('No new types to add — config is already up to date.');
        }

        if ($result->addedKeys !== []) {
            foreach ($result->addedKeys as $key) {
                $this->line("  <fg=green>+</> {$key}");
            }
        }

        if ($result->skippedKeys !== []) {
            $this->newLine();
            $this->components->info('Already present (skipped):');
            foreach ($result->skippedKeys as $key) {
                $this->line("  <fg=gray>·</> {$key}");
            }
        }
    }

    /**
     * @param  array<int, DiscoveredCallSite>  $callSites
     */
    protected function renderSummary(array $callSites, WriteResult $result): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('Call sites scanned', (string) count($callSites));
        $this->components->twoColumnDetail('New type entries written', (string) count($result->addedKeys));
        $this->components->twoColumnDetail('Existing entries left untouched', (string) count($result->skippedKeys));
    }

    /**
     * @param  array<int, mixed>  $items
     */
    protected function count(array $items, string $singular, string $plural): string
    {
        $n = count($items);

        return $n.' '.($n === 1 ? $singular : $plural);
    }
}
