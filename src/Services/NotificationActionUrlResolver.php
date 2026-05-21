<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Services;

use Devletes\NotificationsMax\Contracts\ActionUrlBuilder;
use Devletes\NotificationsMax\Support\NotificationActionAddress;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Picks the right panel for a notification's "View" link at click time,
 * then asks the bound {@see ActionUrlBuilder} to render the URL.
 *
 * Resolution rule, applied in order:
 *
 *   1. `from` panel id (the panel the recipient was on when they clicked)
 *      is in the address's `panels` list AND the recipient can access
 *      that panel → use it.
 *
 *   2. Address's `preferred_panel` is in `panels` (or `panels` is empty
 *      and just a preferred was supplied) AND the recipient can access
 *      it → use the preferred.
 *
 *   3. First panel in `panels` the recipient can access → use it.
 *
 *   4. Address's `preferred_panel` ignoring access checks → use it.
 *      This is a last-resort link; the recipient may still hit a 403,
 *      but at least the URL is well-formed.
 *
 *   5. Otherwise null.
 *
 * "Can access" defers to Filament's {@see FilamentUser} contract when the
 * recipient implements it. When they don't, Filament's stock behaviour
 * is to allow access — we mirror that to avoid hiding valid panels.
 *
 * The `from` value typically comes from the `?from=` query string the
 * redirect controller forwards from the click. Mail clicks have no
 * `from`, in which case the resolver falls straight through to step 2,
 * which matches "URL baked at dispatch time" semantics — the rule that
 * was implicit in the package's pre-multi-panel design.
 */
class NotificationActionUrlResolver
{
    public function __construct(
        protected ActionUrlBuilder $urls,
    ) {}

    /**
     * Resolve the address to an absolute URL string, or null if no panel
     * works. Callers that need to know which panel was chosen can use
     * {@see resolvePanel()} directly and call the URL builder themselves.
     */
    public function resolve(
        NotificationActionAddress $address,
        ?Authenticatable $user = null,
        ?string $fromPanelId = null,
    ): ?string {
        $panelId = $this->resolvePanel($address, $user, $fromPanelId);

        if ($panelId === null) {
            return null;
        }

        return $this->urls->build(
            panelId: $panelId,
            resourceSlug: $address->resource,
            recordId: $address->recordId,
            context: $address->tenantSlug !== null
                ? ['tenant_slug' => $address->tenantSlug]
                : [],
        );
    }

    /**
     * Resolve which panel id the address should route to for this user.
     * Returns null when no acceptable panel exists.
     */
    public function resolvePanel(
        NotificationActionAddress $address,
        ?Authenticatable $user = null,
        ?string $fromPanelId = null,
    ): ?string {
        $panels = $address->panels;
        $preferred = $address->preferredPanel;

        // Step 1: honour the panel the recipient was on at click time.
        if ($fromPanelId !== null && $fromPanelId !== '' && $this->panelIsCandidate($fromPanelId, $panels)) {
            if ($this->userCanAccess($fromPanelId, $user)) {
                return $fromPanelId;
            }
        }

        // Step 2: preferred panel, when it's a valid candidate and accessible.
        if ($preferred !== null && $this->panelIsCandidate($preferred, $panels)) {
            if ($this->userCanAccess($preferred, $user)) {
                return $preferred;
            }
        }

        // Step 3: first accessible panel from the declared list.
        foreach ($panels as $panelId) {
            if ($this->userCanAccess($panelId, $user)) {
                return $panelId;
            }
        }

        // Step 4: last-resort fallback to preferred regardless of access.
        // The recipient may still see a 403, but the link is at least
        // well-formed — better than rendering an action button with no URL.
        if ($preferred !== null) {
            return $preferred;
        }

        return null;
    }

    /**
     * A panel is a candidate when either no constraint list was declared,
     * or the panel appears in the constraint list.
     *
     * @param  array<int, string>  $panels
     */
    protected function panelIsCandidate(string $panelId, array $panels): bool
    {
        return $panels === [] || in_array($panelId, $panels, true);
    }

    /**
     * True when the user is allowed to access the given panel. Users that
     * implement Filament's {@see FilamentUser} contract get the contract
     * check; others fall through to Filament's default-allow behaviour.
     *
     * A null user (e.g. resolving from mail in a job context) bypasses
     * the access check — the address constraints alone govern routing.
     */
    protected function userCanAccess(string $panelId, ?Authenticatable $user): bool
    {
        if ($user === null) {
            return true;
        }

        if (! $user instanceof FilamentUser) {
            // No contract to consult; Filament's stock behaviour for a
            // user model without FilamentUser is to allow every panel.
            return true;
        }

        // Hand `canAccessPanel` a Panel instance. Prefer the registered
        // one (so the host's implementation sees the real config), but
        // fall back to a synthetic panel with just the id when the
        // registry doesn't know about this panel — the contract method's
        // job is to gate access by id, which the synthetic carries.
        $panel = $this->panelFor($panelId) ?? Panel::make()->id($panelId);

        return $user->canAccessPanel($panel);
    }

    protected function panelFor(string $panelId): ?Panel
    {
        try {
            $panels = Filament::getPanels();
        } catch (\Throwable) {
            return null;
        }

        return $panels[$panelId] ?? null;
    }
}
