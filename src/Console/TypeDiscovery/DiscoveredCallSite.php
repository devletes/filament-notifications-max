<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Console\TypeDiscovery;

/**
 * A single `NotificationDispatcher::send()` / `::schedule()` call discovered
 * by the scanner. One per call site, not per type — a type triggered from
 * five places yields five DiscoveredCallSite instances; the command
 * deduplicates by `$typeKey` when synthesising the config entries.
 *
 * `$typeKey` is null when the first argument wasn't a literal string the
 * AST could resolve (variable, concatenation, method call, …). The
 * command reports these as "skipped (dynamic)" so devs know to register
 * them by hand.
 *
 * `$contextKeys` is the list of string keys in the second argument when
 * it's an array literal — used to emit an "Expected context: …" comment
 * next to the generated type entry. Empty when the second argument is
 * a variable, helper call, or `[]`.
 */
final class DiscoveredCallSite
{
    /**
     * @param  array<int, string>  $contextKeys
     */
    public function __construct(
        public readonly ?string $typeKey,
        public readonly array $contextKeys,
        public readonly string $sourceFile,
        public readonly int $sourceLine,
    ) {}

    public function isDynamic(): bool
    {
        return $this->typeKey === null;
    }
}
