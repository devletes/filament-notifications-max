<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Console\TypeDiscovery;

/**
 * Outcome of a {@see TypeConfigWriter::write()} call. The generate command
 * reads these to drive its summary table and exit code.
 *
 * Three success-ish states the command treats differently:
 *   - `addedKeys` non-empty + `wasWritten`  →  file patched, report counts
 *   - `addedKeys` empty + `skippedKeys`     →  nothing new to add ("up to date")
 *   - `couldNotPatch`                       →  existing file had no recognisable
 *                                              closing `];` — fall through to
 *                                              print-only output so the dev
 *                                              can paste manually
 */
final class WriteResult
{
    /**
     * @param  array<int, string>  $addedKeys
     * @param  array<int, string>  $skippedKeys
     */
    public function __construct(
        public readonly array $addedKeys,
        public readonly array $skippedKeys,
        public readonly bool $wasCreated,
        public readonly bool $wasWritten,
        public readonly bool $couldNotPatch = false,
    ) {}
}
