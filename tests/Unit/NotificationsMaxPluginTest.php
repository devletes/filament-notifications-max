<?php

declare(strict_types=1);

use Devletes\NotificationsMax\NotificationsMaxPlugin;
use Filament\Support\Icons\Heroicon;

it('defaults the notification settings nav icon to the outlined bell heroicon', function (): void {
    // Out-of-the-box behaviour: hosts that don't customise get the bell
    // so the settings link is visually identifiable in the sidebar.
    $plugin = new NotificationsMaxPlugin;

    expect($plugin->getNotificationSettingsIcon())->toBe(Heroicon::OutlinedBell);
});

it('accepts a string icon asset name for the notification settings nav icon', function (): void {
    // Filament accepts string asset names (e.g. heroicon-o-* sourced from
    // the host's icon set) anywhere a BackedEnum case would also work.
    // The plugin passes whatever value the host gave through to the page
    // unchanged, so the host's chosen icon convention is honoured.
    $plugin = (new NotificationsMaxPlugin)->notificationSettingsIcon('heroicon-o-cog-6-tooth');

    expect($plugin->getNotificationSettingsIcon())->toBe('heroicon-o-cog-6-tooth');
});

it('accepts a BackedEnum icon for the notification settings nav icon', function (): void {
    // Modern Filament setups use the Heroicon enum for type-safe icon
    // references. Passing an enum case is equivalent to passing the
    // string asset — both flow through the page's getNavigationIcon().
    $plugin = (new NotificationsMaxPlugin)->notificationSettingsIcon(Heroicon::OutlinedCog6Tooth);

    expect($plugin->getNotificationSettingsIcon())->toBe(Heroicon::OutlinedCog6Tooth);
});

it('suppresses the notification settings nav icon when passed null', function (): void {
    // Disabling: hosts (like HRMS) whose information-architecture doesn't
    // use icons in some clusters call `->notificationSettingsIcon(null)`
    // so the settings link renders as plain text. Filament's nav
    // component skips the icon slot when getNavigationIcon() returns null.
    $plugin = (new NotificationsMaxPlugin)->notificationSettingsIcon(null);

    expect($plugin->getNotificationSettingsIcon())->toBeNull();
});

it('chains fluently — notificationSettingsPage()->notificationSettingsIcon(...) returns the plugin', function (): void {
    // The plugin is configured via fluent chaining in the panel provider;
    // each setter must return $this so consumers can keep building.
    $plugin = (new NotificationsMaxPlugin)
        ->notificationSettingsPage()
        ->notificationSettingsIcon(null);

    expect($plugin)->toBeInstanceOf(NotificationsMaxPlugin::class)
        ->and($plugin->hasNotificationSettingsPage())->toBeTrue()
        ->and($plugin->getNotificationSettingsIcon())->toBeNull();
});
