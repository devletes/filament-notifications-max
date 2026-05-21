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
- **`notifications-max:generate-types`** — one artisan command scans your code for dispatcher calls and auto-fills the type registry, so you don't hand-write every entry in `config/notifications.php`

## Architecture

The package is completely domain-agnostic. Everything domain-specific is supplied by the host app through four contracts:

- `BroadcastAudienceResolver` — turns the admin broadcaster's audience picker output into a recipient query
- `ActionUrlBuilder` — builds action-button URLs for notifications (path-based by default; subdomain variant shipped for subdomain-tenant apps)
- `PreferenceResolver` — decides which channels to deliver a given notification type on for a given user
- `TenantResolver` — abstracts multi-tenant vs single-tenant context

Sensible defaults ship; host apps bind their own implementations as needed.

## Generating the type registry

Rather than hand-writing every entry in `config/notifications.php`, run:

```
php artisan notifications-max:generate-types
```

It scans your app's PHP source for `NotificationDispatcher::send()` / `::schedule()` call sites and appends a config entry for each new type key it finds. Re-run as you add notifications — existing entries (with your hand-tuned titles, channels, target panels) are preserved.

**Inferred for you:** the type key, a category (the prefix before the first `.`), a humanised label, the expected context-array shape, and a source-location comment. **You fill in:** titles, bodies, target panel, channel allowlists, and anything else specific to your domain.

**Recognised receiver shapes** — the scanner finds dispatcher calls made through:

- `app(...)` / `resolve(...)` / `App::make(...)`, either inline or via a local variable
- Constructor-injected properties (`$this->dispatcher`), promoted or declared
- Typed method parameters (`function handle(NotificationDispatcher $d)`)
- Named arguments (`->send(typeKey: '...', context: [...])`)
- Closures that `use ($dispatcher)` a tracked outer variable

Calls with a non-literal type key (e.g. `"approval.{$action}"`) can't be statically resolved; the command lists each one's source location as a manual-registration TODO at the end of the run. Patterns that group N similar notifications under one dynamic dispatch are a legitimate design choice — register their keys in `config/notifications.php` by hand and the rest of the workflow works the same.

**Useful flags:**

| Flag | Effect |
|---|---|
| `--path=app/Services` | Scope the scan to a subdirectory (default: `app/`) |
| `--type=foo.bar,baz.qux` | Generate only specific type keys |
| `--exclude` | Treat `--type` as a denylist |
| `--dry-run` | Report what would be added without writing |
| `--print-only` | Print the snippet to stdout, never touch the config file |
| `--force` | Rebuild existing entries from freshly-inferred defaults (default is merge-only) |

The command requires `nikic/php-parser`, listed under `composer suggest` — install with `composer require --dev nikic/php-parser` the first time you reach for it.

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
