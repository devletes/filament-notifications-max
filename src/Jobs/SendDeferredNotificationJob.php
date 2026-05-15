<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Jobs;

use Devletes\NotificationsMax\Queue\RestoreTenantContext;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Carries a deferred dispatch from {@see NotificationDispatcher::send()}
 * (when invoked with a future `$delayUntil`) and re-enters the dispatcher
 * at the scheduled moment.
 *
 * Why a job — and not just notifying with `->delay()` on the notification
 * instance: `GenericNotification` is intentionally NOT `ShouldQueue`, so
 * its built-in `delay()` semantics don't apply. Wrapping the dispatch in
 * this purpose-built job lets us preserve tenant context (via
 * {@see RestoreTenantContext}) and keep rate-limit accounting at
 * execution time rather than scheduling time.
 *
 * The job stores recipient identifiers, not model instances. The
 * dispatcher's `normalizeRecipients()` re-hydrates them at handle time —
 * any users deleted between scheduling and execution simply drop out of
 * the recipient list.
 */
class SendDeferredNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, int>       $userIds
     */
    public function __construct(
        public string $typeKey,
        public array $context,
        public array $userIds,
        public ?int $tenantId = null,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [app(RestoreTenantContext::class)];
    }

    public function handle(NotificationDispatcher $dispatcher): void
    {
        // Re-enter the dispatcher with no delayUntil — we're at the
        // scheduled moment now, so the immediate path runs (rate limit
        // filter + Notification::send). The dispatcher re-hydrates user
        // models from the carried ids, re-resolves type / context /
        // tenant just like a fresh dispatch.
        $dispatcher->send($this->typeKey, $this->context, $this->userIds);
    }
}
