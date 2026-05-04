<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Filament\Pages\Concerns;

use Devletes\NotificationsMax\Registry\NotificationType;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Shared form layout shared by the user-facing preferences page and the
 * admin-facing notification settings page. Both render channel toggles
 * grouped by category → group → type, with the same fieldset/grid pattern.
 *
 * The pieces that DIFFER between the two pages are exposed as abstract
 * methods (state path, channels-per-type, toggle disabled-ness) plus one
 * optional hook (extra row components — used by the admin page to inject
 * a "Manage Content" link).
 *
 * Channel labels are resolved from `config('notifications-max.channels')`
 * so adding a new channel (sms, slack, …) automatically picks up the
 * right human-readable label without trait changes.
 */
trait BuildsNotificationPrefsLayout
{
    /**
     * State path segment used as the prefix for toggle keys. The user
     * preferences page uses `'prefs'`; the admin settings page uses
     * `'settings'`. Final state path is `{prefix}.{safeTypeKey}.{channel}`.
     */
    abstract protected function statePathPrefix(): string;

    /**
     * Channels for which toggles should be rendered for this type. The
     * user prefs page hands back the resolver's admin-filtered list (so
     * users only see what admin allowed); the admin page hands back the
     * type's config-level `allowedChannels` ceiling.
     *
     * @return array<int, string>
     */
    abstract protected function channelsForType(NotificationType $type): array;

    /**
     * Whether toggles for this type should render as disabled. Both pages
     * disable mandatory types — admin can't disable them either; user
     * can't opt out. Hosts that override the page can layer additional
     * lock conditions.
     */
    abstract protected function isToggleDisabled(NotificationType $type): bool;

    /**
     * Extra components rendered alongside the channel toggles on each
     * grouped row. Defaults to none. The admin settings page uses this
     * to inject a "Manage Content" link when the package is in DB
     * content mode.
     *
     * @return array<int, Component>
     */
    protected function extraRowComponents(NotificationType $type): array
    {
        return [];
    }

    /**
     * Flat list of one row per type, regardless of grouping. Group structure
     * lived in nested fieldsets when categories were rendered as tabs;
     * after the page switched to one Section per category, fieldsets just
     * added visual nesting noise. Now every type renders as a single
     * grid row directly under the category Section: label on the left,
     * channel toggles inline to the right, plus any host-injected extras
     * (e.g. the admin "Manage content" link).
     *
     * @param  Collection<int|string, NotificationType>  $categoryTypes
     * @return array<int, Component>
     */
    protected function buildCategoryComponents(Collection $categoryTypes): array
    {
        return $categoryTypes
            ->map(fn (NotificationType $type): Component => $this->typeRow($type))
            ->values()
            ->all();
    }

    /**
     * One row per type. The "group_label" (when present) wins over the
     * type's main label so families of related types — e.g. the seven
     * approval actions sharing the same `approvals` group — read with
     * concise per-action phrases ("Needs your approval", "Approved", …)
     * rather than the longer per-type label.
     */
    protected function typeRow(NotificationType $type): Component
    {
        $safe = $this->safeKey($type->key);
        $rowLabel = $type->groupLabel ?? $type->label;

        if ($type->mandatory) {
            $rowLabel .= '  —  Required';
        }

        $channels = $this->channelsForType($type);
        $extra = $this->extraRowComponents($type);

        // Responsive grid: stacked on mobile, row layout on larger screens.
        // Column count is type-driven (label + N channel toggles + M extras)
        // so apps with exotic channel sets get the right layout
        // automatically without trait changes.
        $totalCols = 1 + count($channels) + count($extra);

        return Grid::make(['default' => 1, 'sm' => $totalCols])
            ->schema([
                Placeholder::make("label.{$safe}")
                    ->hiddenLabel()
                    ->content($rowLabel)
                    ->columnSpan(1),
                ...array_map(
                    fn (string $channel): Toggle => $this->channelToggle($type, $channel),
                    $channels,
                ),
                ...$extra,
            ])
            ->extraAttributes(['data-type-key' => $type->key]);
    }

    protected function channelToggle(NotificationType $type, string $channel): Toggle
    {
        $safe = $this->safeKey($type->key);
        $prefix = $this->statePathPrefix();

        return Toggle::make("{$prefix}.{$safe}.{$channel}")
            ->label($this->channelLabel($channel))
            ->inline()
            ->disabled($this->isToggleDisabled($type))
            ->columnSpan(1);
    }

    /**
     * Turn a dotted type key like "approval.request.action_needed" into a
     * form-safe slug like "approval_request_action_needed". Form state
     * paths use dots as separators, so keeping type keys out of the path
     * avoids nesting surprises.
     */
    protected function safeKey(string $key): string
    {
        return str_replace('.', '_', $key);
    }

    /**
     * Channel label resolution. Reads from the channel registry first
     * (handles both `push` / `email` and host-added channels uniformly),
     * falls back to a humanised version of the channel key.
     */
    protected function channelLabel(string $channel): string
    {
        $configured = config("notifications-max.channels.{$channel}.label");

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return Str::headline($channel);
    }
}
