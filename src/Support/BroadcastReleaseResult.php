<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Support;

/**
 * Value object returned from {@see \Devletes\NotificationsMax\Contracts\BroadcastReleasePipeline::handle()}.
 *
 * Carries the admin-facing feedback shown as a Filament toast after the
 * Publish action runs. Pipelines describe what happened ("Broadcast queued",
 * "Submitted for approval", "Held for review") and, optionally, any
 * secondary context ("3 reviewers notified").
 *
 * The plugin does not make assumptions about this text — it displays it as
 * the pipeline provides it. That means host-app pipelines get free control
 * over terminology without needing to touch resource code.
 */
final class BroadcastReleaseResult
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $body = null,
    ) {}
}
