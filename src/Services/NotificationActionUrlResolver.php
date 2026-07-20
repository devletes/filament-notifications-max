<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Services;

use Devletes\NotificationsMax\Contracts\ActionUrlBuilder;
use Devletes\NotificationsMax\Support\NotificationActionAddress;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Filament\Resources\Resource;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Picks the right panel for a notification's "View" link at click time.
 *
 * Resolution order:
 *   1. `$fromPanelId` if it's a valid candidate and accessible.
 *   2. `preferred_panel` if it's a valid candidate and accessible.
 *   3. First panel in `panels` the user can access.
 *   4. `preferred_panel` regardless of access (last-resort well-formed URL).
 *   5. null.
 *
 * Mail clicks have no `$fromPanelId` → step 2 catches the typical case.
 *
 * Beyond panel choice, {@see resolve()} also decides the URL *form*: the
 * detail path (`/{resource}/{id}`) or Filament's index-page table-action
 * query form (`/{resource}?tableAction=…&tableActionRecord=…`). An explicit
 * `tableAction` on the address always wins; otherwise
 * {@see detectTableAction()} inspects the chosen panel's resource to pick
 * the form that actually routes.
 */
class NotificationActionUrlResolver
{
    public function __construct(
        protected ActionUrlBuilder $urls,
    ) {}

    public function resolve(
        NotificationActionAddress $address,
        ?Authenticatable $user = null,
        ?string $fromPanelId = null,
    ): ?string {
        $panelId = $this->resolvePanel($address, $user, $fromPanelId);

        if ($panelId === null) {
            return null;
        }

        // Explicit address table action beats detection in BOTH
        // directions: it can force the query form even when the resource
        // has a detail page, and its name is used verbatim even when
        // detection would have injected 'view'.
        $tableAction = $address->tableAction ?? $this->detectTableAction($panelId, $address);

        return $this->urls->build(
            panelId: $panelId,
            resourceSlug: $address->resource,
            recordId: $address->recordId,
            context: array_filter([
                'tenant_slug' => $address->tenantSlug,
                'table_action' => $tableAction,
            ], static fn ($v): bool => $v !== null && $v !== ''),
        );
    }

    /**
     * Decide, for an address WITHOUT an explicit table action, whether the
     * URL must use the index-page query form because the target resource
     * has no detail page.
     *
     * Many resources never register a 'view' page — their records open in
     * a `ViewAction` modal on the list page. For those, the detail path
     * `/{panel}/{resource}/{id}` isn't a registered route and 404s. Rather
     * than making every host declare `action_table_action` per type, this
     * looks the resource up in the resolved panel and inspects its
     * registered pages: no 'view' page → inject `'view'` so the URL opens
     * the list page's view modal; 'view' page present → keep the detail
     * path exactly as today.
     *
     * Why detect at CLICK time rather than dispatch time: this resolver
     * runs inside the `/go/` redirect request, where every panel (and its
     * resources) is registered — so the lookup is authoritative. It also
     * means every ALREADY-STORED notification row pointing at a modal-only
     * resource is healed on its next click, with no re-dispatch; a
     * dispatch-time decision would be baked into the row and go stale the
     * moment a resource gains or loses its view page. Dispatch can also
     * happen where panels aren't reliably registered (queue workers,
     * artisan), which would make dispatch-time detection flaky on top of
     * stale.
     *
     * Fails open BY DESIGN: any lookup failure — panel not registered
     * (CLI resolution outside a booted Filament app), resource slug not
     * found on the panel, or anything a resource class throws while
     * reporting its slug/pages — returns null, i.e. today's detail-path
     * behavior. URL resolution must never break because this heuristic
     * couldn't run. Gated by `notifications-max.auto_table_action`
     * (default on).
     */
    protected function detectTableAction(string $panelId, NotificationActionAddress $address): ?string
    {
        if (! config('notifications-max.auto_table_action', true)) {
            return null;
        }

        try {
            $panel = $this->panelFor($panelId);

            if ($panel === null) {
                return null;
            }

            $slug = trim($address->resource, '/');

            foreach ($panel->getResources() as $resourceClass) {
                // Covers autoloadability AND the Resource contract in one
                // check, so a stray registration entry is skipped rather
                // than aborting detection for the remaining resources.
                if (! is_string($resourceClass) || ! is_subclass_of($resourceClass, Resource::class)) {
                    continue;
                }

                // Slugs are compared as trimmed literals — the address's
                // `resource` is authored as (or synthesized from) the same
                // slug string the builders splice into the path, so there
                // is no normalisation beyond stray-slash tolerance.
                if (trim((string) $resourceClass::getSlug($panel), '/') !== $slug) {
                    continue;
                }

                // 'view' is both Filament's conventional route name for
                // the detail page AND the default name of the table
                // `ViewAction` that replaces it on modal-on-list
                // resources — hence the same literal on both sides.
                return $resourceClass::hasPage('view') ? null : 'view';
            }
        } catch (\Throwable) {
            // Fall through — resolution must never throw because of this.
        }

        return null;
    }

    public function resolvePanel(
        NotificationActionAddress $address,
        ?Authenticatable $user = null,
        ?string $fromPanelId = null,
    ): ?string {
        $panels = $address->panels;
        $preferred = $address->preferredPanel;

        if ($fromPanelId !== null && $fromPanelId !== '' && $this->panelIsCandidate($fromPanelId, $panels)) {
            if ($this->userCanAccess($fromPanelId, $user)) {
                return $fromPanelId;
            }
        }

        if ($preferred !== null && $this->panelIsCandidate($preferred, $panels)) {
            if ($this->userCanAccess($preferred, $user)) {
                return $preferred;
            }
        }

        foreach ($panels as $panelId) {
            if ($this->userCanAccess($panelId, $user)) {
                return $panelId;
            }
        }

        return $preferred;
    }

    /**
     * @param  array<int, string>  $panels
     */
    protected function panelIsCandidate(string $panelId, array $panels): bool
    {
        return $panels === [] || in_array($panelId, $panels, true);
    }

    protected function userCanAccess(string $panelId, ?Authenticatable $user): bool
    {
        if ($user === null || ! $user instanceof FilamentUser) {
            return true;
        }

        // Synthetic Panel covers panels the registry doesn't know about;
        // most canAccessPanel impls only inspect the id.
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
