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
    // Disabling: hosts whose information-architecture doesn't
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

it('defaults preferences-page nav visibility to off and overrides to null', function (): void {
    // Out-of-the-box behaviour: the preferences page registers but stays
    // hidden from the sidebar — hosts reach it via the NotificationCenter
    // header action. All four presentation accessors return null until
    // the host overrides them, so the page falls back to its own
    // static defaults.
    $plugin = new NotificationsMaxPlugin;

    expect($plugin->hasPreferencesPageInNavigation())->toBeFalse()
        ->and($plugin->getPreferencesPageNavigationGroup())->toBeNull()
        ->and($plugin->getPreferencesPageNavigationLabel())->toBeNull()
        ->and($plugin->getPreferencesPageNavigationIcon())->toBeNull()
        ->and($plugin->getPreferencesPageTitle())->toBeNull();
});

it('chains fluently — preferencesPage() + the five new presentation setters', function (): void {
    // Every new setter must return $this so panel providers can compose
    // them inline. The hostage-scenario fixture (group=Settings,
    // label=Notifications, title=Preferences) is exactly the HRMS
    // employee-panel shape and exercises the full surface in one go.
    $plugin = (new NotificationsMaxPlugin)
        ->preferencesPage()
        ->preferencesPageInNavigation()
        ->preferencesPageNavigationGroup('Settings')
        ->preferencesPageNavigationLabel('Notifications')
        ->preferencesPageNavigationIcon(Heroicon::OutlinedBell)
        ->preferencesPageTitle('Preferences');

    expect($plugin)->toBeInstanceOf(NotificationsMaxPlugin::class)
        ->and($plugin->hasPreferencesPage())->toBeTrue()
        ->and($plugin->hasPreferencesPageInNavigation())->toBeTrue()
        ->and($plugin->getPreferencesPageNavigationGroup())->toBe('Settings')
        ->and($plugin->getPreferencesPageNavigationLabel())->toBe('Notifications')
        ->and($plugin->getPreferencesPageNavigationIcon())->toBe(Heroicon::OutlinedBell)
        ->and($plugin->getPreferencesPageTitle())->toBe('Preferences');
});

it('accepts a string icon asset name for the preferences page nav icon', function (): void {
    // Same string-or-enum acceptance as notificationSettingsIcon — hosts
    // using string asset names get the same flexibility.
    $plugin = (new NotificationsMaxPlugin)->preferencesPageNavigationIcon('heroicon-o-bell-alert');

    expect($plugin->getPreferencesPageNavigationIcon())->toBe('heroicon-o-bell-alert');
});

it('preferencesPageInNavigation(false) explicitly disables sidebar registration', function (): void {
    // The boolean parameter lets hosts conditionally enable based on
    // some external state without breaking the fluent chain.
    $plugin = (new NotificationsMaxPlugin)
        ->preferencesPage()
        ->preferencesPageInNavigation(false);

    expect($plugin->hasPreferencesPage())->toBeTrue()
        ->and($plugin->hasPreferencesPageInNavigation())->toBeFalse();
});
