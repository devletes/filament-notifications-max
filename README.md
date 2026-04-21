# Filament Notifications Max

Real-time, preference-driven notifications for Filament.

> **Status: pre-release.** Under active development inside the HRMS project. API is not yet stable. Do not use in other projects until the first tagged release.

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

The package is completely domain-agnostic. Everything domain-specific is supplied by the host app through five contracts:

- `AudienceResolver` — turns audience criteria into a `Collection<User>`
- `AdminRoleResolver` — decides who can send broadcasts and who counts as an admin
- `ActionUrlBuilder` — builds action-button URLs for notifications
- `TenantResolver` — abstracts multi-tenant vs single-tenant
- `AuthorizedBroadcaster` — optional finer-grained broadcast authorization

Sensible defaults ship; host apps bind their own implementations as needed.

## License

MIT
