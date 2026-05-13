# Changelog

All notable changes to `devletes/filament-notifications-max` are documented in
this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- `GenericNotification` is no longer `final`. The per-type `notification_class`
  registry escape hatch was unusable while the class was sealed; host apps can
  now subclass it to customise mail templates, queue policy, or per-channel
  rendering on a per-type basis.
- `SendBroadcastJob` no longer restricts the audience query to `id` +
  `tenant_id`. The full user model is now hydrated so the mail channel can
  route via `routeNotificationForMail()` and host-added channels see the
  recipient attributes they declare on the notifiable.
- `AudienceRelationManager`'s read/unread subquery now filters by
  `notifiable_type` and resolves the user table name dynamically. Hosts that
  use notifications across multiple notifiable types, or that run a
  non-standard user table, get the correct rows back instead of accidental
  cross-type matches.

### Changed

- `NotificationsMaxPlugin::broadcasterNavigationGroup()` accepts `UnitEnum` in
  addition to `string|null`, matching Filament's native navigation-group
  typing so apps that use enum nav groups can pass their enum case directly.
- `NotificationType::fromConfig`'s last-resort fallback channel set switched
  from `['database', 'broadcast']` / `['database', 'broadcast', 'mail']` to
  `['push']` / `['push', 'email']`. Brings the fallback in line with the
  logical-channel naming used by the rest of the package; the physical
  expansion (push → database+broadcast, email → mail) happens later in
  `EloquentPreferenceResolver::expandLogicalChannels()`.
- `NotificationCenter` bulk Mark-as-read / Mark-as-unread actions run a
  single `UPDATE … WHERE id IN (…)` instead of one query per record. Mark-as-
  read additionally filters to `read_at IS NULL` so re-marking already-read
  rows doesn't overwrite their original read timestamp.
- `NotificationCenter` category filter uses a single `whereIn` against the
  JSON path instead of an OR-chain of equal checks — same SQL semantics, one
  cleaner query.
- `BroadcastNotificationResource::allowedChannelOptions()` resolves channel
  labels from the channel registry (`notifications-max.channels.{c}.label`)
  instead of hardcoding "Push" / "Email", so host-added channels pick up
  their configured labels automatically.

### Added

- `notifications-max.broadcaster.chunk_size` config (default `100`) controls
  the chunk size `SendBroadcastJob` uses when fanning out to recipients.
- `NotificationsMaxPlugin::getVersion()` accessor returns the installed
  package version from Composer's runtime metadata.
- `LICENSE` (MIT) and this `CHANGELOG.md`.

### Internal

- `declare(strict_types=1)` added to `NotificationsMaxServiceProvider` for
  consistency with the rest of the package.
- `NotificationDispatcher::makeNotification` validation simplified to a
  single `is_a()` check.
- `RoleBasedBroadcastAudienceResolver::extractRoleIds` deduplicates input
  role ids before query.
- `AudienceResolver::hasAttribute()` caches schema-column lookups across
  keystrokes so the autocomplete picker doesn't re-probe schema on every
  input event.
- `NotificationContentResolver::shouldUseDatabase()` memoises its config
  lookup per resolver instance.
- `FireDatabaseNotificationsSent` rationale comment corrected — the local
  `BroadcastException` catch is for richer logging context, not for
  `afterCommit` semantics (the notification class does not use that trait).

## [0.2.0] - 2026-05-13

Initial feature-complete pre-release. Admin broadcaster, notification center,
notification settings page, database content mode, channel-aware content
resolver, per-user preferences UI, real-time bell via Reverb. See commit
history for full details.

## [0.1.0]

Internal scaffolding release. Real-time bell, dispatcher facade, basic
preferences.
