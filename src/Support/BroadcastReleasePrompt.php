<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Support;

/**
 * Describes how the Publish action should present itself to an admin for a
 * given broadcast in a given state. Returned by
 * {@see \Devletes\NotificationsMax\Contracts\BroadcastReleasePipeline::describeAction()}.
 *
 * Separated from {@see BroadcastReleaseResult} because the prompt is shown
 * BEFORE the pipeline runs (button label + confirmation modal) while the
 * result is shown AFTER (toast). Two distinct UI moments; two value objects
 * so neither leaks into the other's lifecycle.
 *
 * Why pipeline-owned:
 *   The shipped default pipeline (ImmediateBroadcastReleasePipeline) treats
 *   the action as "Publish now" so the button just says "Publish". An
 *   approval-gated host pipeline treats a draft click as "Submit for
 *   Approval" — label and confirmation phrase change to match. Keeping
 *   both under the pipeline contract means the resource view page stays
 *   generic and hosts don't have to subclass the page to rewrite labels.
 */
final class BroadcastReleasePrompt
{
    public function __construct(
        /** Short button label, e.g. "Publish" / "Submit for Approval". */
        public readonly string $label,

        /**
         * Confirmation modal body shown before the pipeline runs. Omit
         * (null) to skip the confirmation step.
         */
        public readonly ?string $confirmation = null,

        /** Filament button color, e.g. 'primary', 'warning'. */
        public readonly string $color = 'primary',

        /** Filament heroicon name. */
        public readonly string $icon = 'heroicon-o-paper-airplane',
    ) {}
}
