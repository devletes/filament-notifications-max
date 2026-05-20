# Filament Notifications Max

Real-time, preference-driven notifications for Filament.

> **Status: pre-release.** Under active development; API is not yet stable. Do not use in other projects until the first tagged release.

## What it will do

- Real-time in-app notifications via Laravel Reverb + Echo, delivered to Filament's native bell
- Per-user notification preferences (toggle each notification type × channel)
- Email + database + broadcast channels, configurable per type
- Admin broadcaster — compose a custom notification and send to a filtered audience
- Full-page notification center with filters and bulk actions
- Built-in rate limiting to prevent notification storms
- Multi-tenant aware (opt-in)
- Domain-agnostic: a single `GenericNotification` class driven by a type-key registry

## Architecture

The package is completely domain-agnostic. Everything domain-specific is supplied by the host app through four contracts:

- `BroadcastAudienceResolver` — turns the admin broadcaster's audience picker output into a recipient query
- `ActionUrlBuilder` — builds action-button URLs for notifications (path-based by default; subdomain variant shipped for subdomain-tenant apps)
- `PreferenceResolver` — decides which channels to deliver a given notification type on for a given user
- `TenantResolver` — abstracts multi-tenant vs single-tenant context

Sensible defaults ship; host apps bind their own implementations as needed.

## Audience resolvers

Two `BroadcastAudienceResolver` implementations ship with the package:

- **`Defaults\AudienceResolver`** (default) — audience picker is a multi-select of users from the app's configured user model (`config('auth.providers.users.model')`). Tenant-scoped. Works out of the box with no extra dependencies.
- **`Defaults\RoleBasedBroadcastAudienceResolver`** — audience picker is a multi-select of Spatie roles; recipients are the users assigned to any of the selected roles. Use this if your app uses [spatie/laravel-permission](https://github.com/spatie/laravel-permission) and you prefer to target broadcasts by role membership.

Switch the default by pointing the config at the class you want:

```php
// config/notifications-max.php
'broadcaster' => [
    'audience_resolver' => \Devletes\NotificationsMax\Defaults\RoleBasedBroadcastAudienceResolver::class,
],
```

Apps with richer targeting (departments, locations, boolean rules, etc.) can bind their own implementation of the `BroadcastAudienceResolver` contract and point the config at it.

## License

MIT
