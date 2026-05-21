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

        return $this->urls->build(
            panelId: $panelId,
            resourceSlug: $address->resource,
            recordId: $address->recordId,
            context: $address->tenantSlug !== null
                ? ['tenant_slug' => $address->tenantSlug]
                : [],
        );
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
