<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Services;

use Devletes\NotificationsMax\Contracts\TenantResolver;
use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Registry\NotificationType;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

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

        // Rate limiting filters recipients individually so a partially-
        // throttled audience still receives the notification for the
        // recipients who are under their limit. Mandatory types bypass.
        if (! $type->mandatory) {
            $users = $this->filterByRateLimit($users, $type);

            if ($users->isEmpty()) {
                return;
            }
        }

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
     * Construct the Laravel Notification instance to dispatch. Resolution
     * order:
     *
     *   1. Per-type override — a type registry entry's `notification_class`
     *      field. Wins over everything else, so a single type can use a
     *      specialised subclass without affecting others.
     *   2. Host-wide default — the `notifications-max.default_notification_class`
     *      config. The escape hatch for adding a `to{Channel}` method
     *      that the package doesn't ship a handler for: subclass
     *      GenericNotification, add your method, set this config.
     *   3. Package default — `GenericNotification` itself.
     *
     * Whichever class is chosen is constructed with the same
     * `(typeKey, context)` signature, so subclasses just extend
     * `GenericNotification` and override what they need.
     *
     * @param  array<string, mixed>  $context
     */
    protected function makeNotification(string $typeKey, array $context, ?string $notificationClass): \Illuminate\Notifications\Notification
    {
        $class = $notificationClass
            ?? config('notifications-max.default_notification_class')
            ?? GenericNotification::class;

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
     * Filter the recipient collection down to users who are under their
     * per-(user, type) rate limit. Each surviving recipient's counter is
     * incremented as a side effect — calling `filter` plus `hit` in the
     * same pass keeps the check + record atomic from the dispatcher's
     * point of view.
     *
     * Returns the collection unchanged when no rate limit applies (type
     * declares no rate limit and the global default is disabled).
     */
    protected function filterByRateLimit(Collection $users, NotificationType $type): Collection
    {
        $config = $this->resolveRateLimitConfig($type);

        if ($config === null) {
            return $users;
        }

        [$max, $perSeconds] = $config;

        return $users->filter(function ($user) use ($type, $max, $perSeconds): bool {
            $userId = $this->userIdFor($user);

            // Can't rate-limit an unidentified notifiable — pass through
            // rather than silently drop. Caller is responsible for either
            // hydrating identifiable models or accepting the bypass.
            if ($userId === null) {
                return true;
            }

            $key = "notif-throttle:{$userId}:{$type->key}";

            if (RateLimiter::tooManyAttempts($key, $max)) {
                return false;
            }

            RateLimiter::hit($key, $perSeconds);

            return true;
        })->values();
    }

    /**
     * Resolve the effective rate-limit config for a type. Per-type entry
     * (`NotificationType::$rateLimit`) wins over the package's
     * `notifications-max.rate_limits.default` map. Returns null when
     * `max` is zero or negative — semantically "unlimited" — so the
     * dispatcher can short-circuit the per-user filter loop entirely.
     *
     * @return array{int, int}|null  [max attempts, window in seconds]
     */
    protected function resolveRateLimitConfig(NotificationType $type): ?array
    {
        $config = $type->rateLimit ?? config('notifications-max.rate_limits.default', []);

        if (! is_array($config)) {
            return null;
        }

        $max = (int) ($config['max'] ?? 0);

        if ($max <= 0) {
            return null;
        }

        // Enforce a sensible minimum window so a misconfigured 0-minute
        // entry doesn't produce a divide-by-zero or 0-TTL cache write.
        $perMinutes = max(1, (int) ($config['per_minutes'] ?? 5));

        return [$max, $perMinutes * 60];
    }

    /**
     * Best-effort id extraction. Filament users implement Authenticatable,
     * but the dispatcher accepts any notifiable shape — fall back to a
     * direct `->id` read for plain Eloquent models that don't expose the
     * auth identifier.
     */
    protected function userIdFor(mixed $user): int|string|null
    {
        if (! is_object($user)) {
            return null;
        }

        if (method_exists($user, 'getAuthIdentifier')) {
            return $user->getAuthIdentifier();
        }

        return $user->id ?? null;
    }

    /**
     * Defensive check: all recipients must share the same `tenant_id`. A
     * dispatch that spans tenants is always a bug — someone mis-scoped a
     * query, or they're reusing a dispatch across multiple tenants.
     *
     * Counts null as a distinct value so a mixed (tenanted + null) list
     * also fails the check — a null-tenant user landing in a tenanted
     * dispatch is just as likely to be a scoping bug as two non-null
     * tenants. Single-tenant installs see every recipient with null,
     * which is one unique value → passes.
     */
    protected function assertSingleTenant(Collection $users, array $context): void
    {
        $tenantIds = $users
            ->map(fn ($u) => $u->tenant_id ?? null)
            ->unique()
            ->values();

        if ($tenantIds->count() > 1) {
            throw new \RuntimeException(sprintf(
                'NotificationDispatcher: recipients span multiple tenants [%s]. Split dispatch per tenant.',
                $tenantIds->map(fn ($id) => $id === null ? 'null' : (string) $id)->implode(', '),
            ));
        }
    }
}
