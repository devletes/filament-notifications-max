<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Concerns;

use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * Drop-in replacement for Laravel's {@see Notifiable} trait that drives
 * per-channel routing from the package's channel registry instead of
 * requiring a `routeNotificationForX` method per channel on the
 * notifiable.
 *
 * Usage — replace `use Notifiable;` with `use NotifiableViaMax;` on your
 * notifiable model (typically `App\Models\User`). One-time setup, then
 * every notifications-max channel install (slack, sms, …) routes via the
 * attribute it declared in config — zero further model edits.
 *
 * ## Routing precedence
 *
 *   1. Host-defined `routeNotificationForX($notification)` method on the
 *      model wins (Laravel's standard escape hatch — define one to take
 *      manual control of a specific channel).
 *
 *   2. Channel registry: the first entry under
 *      `config('notifications-max.channels')` whose `physical` list
 *      contains the driver. Its `route_via` key names an attribute on
 *      the notifiable; the attribute's value is returned. Empty / null
 *      values return `false`, which Laravel's slack/sms/etc. channels
 *      treat as "skip delivery for this notifiable" — preferred over
 *      returning null, which some channels mishandle by attempting to
 *      dispatch to a null destination.
 *
 *   3. Laravel built-ins: `database` returns the `notifications()`
 *      relation; `mail` returns `$this->email`. Any other driver
 *      without a registry entry or host-defined method returns `false`.
 *
 * ## Why not just compose with Notifiable + define routeNotificationForX?
 *
 * Every new channel (slack, sms, discord, teams, …) would require
 * another method on every notifiable model. The registry already names
 * the destination attribute (`route_via`) so the trait reads it once
 * and serves every channel.
 *
 * Host-defined `routeNotificationForX` methods still take precedence —
 * use them when one specific channel needs custom routing logic.
 */
trait NotifiableViaMax
{
    use Notifiable {
        routeNotificationFor as private notifiableTraitRouteFor;
    }

    /**
     * @param  string  $driver  Physical channel name (the value Laravel's
     *                          ChannelManager resolves — `mail`, `slack`,
     *                          `database`, `vonage`, …).
     */
    public function routeNotificationFor($driver, $notification = null): mixed
    {
        // (1) Host-defined override per channel — same convention as
        // Laravel's stock Notifiable. The __FUNCTION__ guard prevents
        // a host method literally named `routeNotificationFor` from
        // recursing into itself.
        $method = 'routeNotificationFor' . Str::studly($driver);

        if ($method !== __FUNCTION__ && method_exists($this, $method)) {
            return $this->{$method}($notification);
        }

        // (2) Channel registry. Walk logical channels until one's
        // `physical` list contains the driver.
        foreach (config('notifications-max.channels', []) as $def) {
            if (! is_array($def) || ! in_array($driver, $def['physical'] ?? [], true)) {
                continue;
            }

            // Matched the channel but it doesn't declare a route_via —
            // stop searching and fall through to Laravel's built-ins.
            // Falls through, not `return false`, so `mail` keeps working
            // even when the host hasn't set `route_via => 'email'`.
            if (! isset($def['route_via'])) {
                break;
            }

            $value = $this->getAttribute($def['route_via']);

            return ($value !== null && $value !== '') ? $value : false;
        }

        // (3) Built-in fallback for the always-available drivers.
        // Inlined rather than delegated to the base trait because
        // Laravel's match has no default arm and would throw on any
        // un-registered driver (e.g. slack without a route_via).
        return match ($driver) {
            'database' => $this->notifications(),
            'mail' => $this->getAttribute('email'),
            default => false,
        };
    }
}
