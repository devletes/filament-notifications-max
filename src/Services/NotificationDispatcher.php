<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Services;

use Devletes\NotificationsMax\Contracts\TenantResolver;
use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The single public entry point for domain code that wants to emit a
 * notification.
 *
 *   app(NotificationDispatcher::class)->send(
 *       typeKey: 'approval.request.action_needed',
 *       context: [...],
 *       recipients: $users,
 *   );
 *
 * Handles:
 *   - Recipient normalization (accept User, Collection, array<int>)
 *   - Automatic tenant_slug injection from the tenant resolver
 *   - Cross-tenant defensive assertion (recipients must share tenant_id)
 *   - Delegating to Laravel's Notification facade (which queues, fires
 *     broadcast events, persists to db — per channels chosen by the
 *     GenericNotification::via())
 */
class NotificationDispatcher
{
    public function __construct(
        protected NotificationTypeRegistry $registry,
        protected TenantResolver $tenantResolver,
    ) {}

    /**
     * @param  iterable<int, mixed>|Authenticatable|Model  $recipients
     * @param  array<string, mixed>                        $context
     */
    public function send(string $typeKey, array $context, mixed $recipients): void
    {
        // Verify the type exists up front — fail loudly if domain code
        // misspells a key instead of silently never delivering.
        $type = $this->registry->find($typeKey);

        $users = $this->normalizeRecipients($recipients);

        if ($users->isEmpty()) {
            return;
        }

        $context = $this->enrichContext($context, $users);

        $this->assertSingleTenant($users, $context);

        // Laravel's notification sender iterates channels in via() order
        // (database first, broadcast second, mail last, per our config/channels
        // map). The broadcast channel is the one we expect to flake — a Reverb
        // outage, a transient socket failure — so wrap the send with a
        // BroadcastException-specific catch: the database row already
        // persisted, the user will see the notification on the next bell poll
        // (30s polling fallback), and we log a warning rather than 500ing the
        // triggering request.
        //
        // Any OTHER exception — a bug in a host's notification subclass, a
        // mail driver misconfiguration, a database write failure — propagates
        // to the caller. Hiding those behind a catch-all Throwable masks real
        // bugs as "partial delivery" warnings and makes the system harder to
        // debug; if the dispatcher genuinely needs to swallow more, the host
        // should wrap the call site instead.
        try {
            Notification::send($users, $this->makeNotification($type->key, $context, $type->notificationClass));
        } catch (BroadcastException $e) {
            Log::warning('notifications-max: broadcast channel failed', [
                'type_key' => $type->key,
                'recipients' => $users->pluck('id')->all(),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Construct the Laravel Notification instance to dispatch. Defaults to
     * the package's own `GenericNotification`; per-type registry entries may
     * declare `notification_class => MyCustomNotification::class` to swap in
     * a host-app subclass with custom mail templates, queue policy, etc.
     *
     * Custom classes are constructed with the same `(typeKey, context)`
     * signature, so they typically extend `GenericNotification` and override
     * only the methods they want to specialise.
     *
     * @param  array<string, mixed>  $context
     */
    protected function makeNotification(string $typeKey, array $context, ?string $notificationClass): \Illuminate\Notifications\Notification
    {
        $class = $notificationClass ?? GenericNotification::class;

        // `is_a($class, $base, allow_string: true)` matches both the class
        // itself and any subclass — covers GenericNotification and host
        // subclasses uniformly without the awkward OR-with-equality dance
        // the previous check needed.
        if (! is_a($class, \Illuminate\Notifications\Notification::class, true)) {
            throw new \RuntimeException(sprintf(
                'Notification class [%s] for type [%s] must extend Illuminate\\Notifications\\Notification.',
                $class,
                $typeKey,
            ));
        }

        return new $class($typeKey, $context);
    }

    /**
     * Accept: single User, Collection<User>, array<int> of user IDs, or an
     * iterable producing any mix. Normalize to a Collection of models.
     *
     * @param  mixed  $recipients
     */
    protected function normalizeRecipients(mixed $recipients): Collection
    {
        if ($recipients instanceof Authenticatable || $recipients instanceof Model) {
            return collect([$recipients]);
        }

        if ($recipients instanceof Collection) {
            return $recipients;
        }

        if (is_iterable($recipients)) {
            $items = collect($recipients);

            // If it's a list of IDs, hydrate to User models.
            if ($items->isNotEmpty() && is_numeric($items->first())) {
                $userClass = config('auth.providers.users.model');

                if ($userClass && class_exists($userClass)) {
                    return $userClass::query()->whereIn('id', $items->all())->get();
                }
            }

            return $items;
        }

        return collect();
    }

    /**
     * Ensure `tenant_slug` is populated in context so URL builders can form
     * absolute subdomain URLs. Pulls from the tenant resolver if absent.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function enrichContext(array $context, Collection $users): array
    {
        if (isset($context['tenant_slug'])) {
            return $context;
        }

        $tenant = $this->tenantResolver->current();

        if ($tenant && isset($tenant->slug)) {
            $context['tenant_slug'] = $tenant->slug;

            return $context;
        }

        // No tenant bound to the request — fall back to the first recipient's
        // tenant via the resolver contract. The default resolver follows the
        // conventional `User->tenant->slug` relation; host apps with a
        // different shape implement TenantResolver::slugFor() themselves.
        if ($users->isNotEmpty()) {
            $first = $users->first();

            if (is_object($first)) {
                $slug = $this->tenantResolver->slugFor($first);

                if ($slug !== null) {
                    $context['tenant_slug'] = $slug;
                }
            }
        }

        return $context;
    }

    /**
     * Defensive check: all recipients must share the same `tenant_id`. Having
     * a Collection span tenants in a single dispatch is always a bug — either
     * someone mis-scoped a query, or they're reusing a dispatch for multiple
     * tenants. Throw early so it surfaces in development.
     */
    protected function assertSingleTenant(Collection $users, array $context): void
    {
        $tenantIds = $users
            ->map(fn ($u) => $u->tenant_id ?? null)
            ->reject(fn ($v) => $v === null)
            ->unique()
            ->values();

        if ($tenantIds->count() > 1) {
            throw new \RuntimeException(sprintf(
                'NotificationDispatcher: recipients span multiple tenants [%s]. Split dispatch per tenant.',
                $tenantIds->implode(', '),
            ));
        }
    }
}
