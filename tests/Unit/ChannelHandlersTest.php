<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Channels\BroadcastChannelHandler;
use Devletes\NotificationsMax\Channels\DatabaseChannelHandler;
use Devletes\NotificationsMax\Channels\MailChannelHandler;
use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Devletes\NotificationsMax\Services\NotificationContentResolver;
use Devletes\NotificationsMax\Tests\Stubs\User;
use Filament\Facades\Filament;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

beforeEach(function (): void {
    config(['notifications' => [
        'demo.simple' => [
            'title' => 'Title {name}',
            'body' => 'Body {name}',
            'default_channels' => ['push'],
            'allowed_channels' => ['push', 'email'],
        ],
        'demo.with.email' => [
            'title' => 'Top title',
            'body' => 'Top body',
            'content' => [
                'email' => [
                    'subject' => 'Email subject {name}',
                    'body' => '<p>Email body {name}</p>',
                    'template' => 'default',
                ],
            ],
            'default_channels' => ['email'],
            'allowed_channels' => ['email'],
        ],
    ]]);
    app(NotificationTypeRegistry::class)->flush();
});

it('DatabaseChannelHandler returns the notification\'s buildFilamentPayload', function (): void {
    $notification = new GenericNotification('demo.simple', ['name' => 'Alice']);

    $payload = (new DatabaseChannelHandler)->send(new stdClass, $notification);

    expect($payload)->toBeArray()
        ->and($payload)->toBe($notification->buildFilamentPayload());
});

it('BroadcastChannelHandler wraps resolveBroadcastData in a BroadcastMessage', function (): void {
    $notification = new GenericNotification('demo.simple', ['name' => 'Bob']);

    $message = (new BroadcastChannelHandler)->send(new stdClass, $notification);

    expect($message)->toBeInstanceOf(BroadcastMessage::class)
        ->and($message->data)->toBe($notification->resolveBroadcastData());
});

it('MailChannelHandler builds a MailMessage with subject + body from channel content', function (): void {
    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.with.email', ['name' => 'Carol']);

    $handler = new MailChannelHandler(app(NotificationContentResolver::class));
    $message = $handler->send($user, $notification);

    expect($message)->toBeInstanceOf(MailMessage::class)
        ->and($message->subject)->toBe('Email subject Carol');
});

it('MailChannelHandler falls back to type top-level title/body when email content is absent', function (): void {
    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.simple', ['name' => 'Dan']);

    $handler = new MailChannelHandler(app(NotificationContentResolver::class));
    $message = $handler->send($user, $notification);

    // Top-level title is used as the email subject via the resolver
    // fallback.
    expect($message->subject)->toBe('Title Dan');
});

it('MailChannelHandler resolves a registered template view', function (): void {
    config(['notifications-max.email_templates' => [
        'default' => 'filament-notifications-max::mail.default',
    ]]);

    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.with.email', ['name' => 'Eve']);

    $message = (new MailChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->markdown)->toBe('filament-notifications-max::mail.default');
});

it('MailChannelHandler falls back to the first registered template when name is misspelled and logs a warning', function (): void {
    // Two registered templates so we can distinguish "fell back to first"
    // from "happened to find the one we asked for". Both point at the same
    // shipped view to keep the fixture self-contained.
    config(['notifications-max.email_templates' => [
        'first-template' => 'filament-notifications-max::mail.default',
        'branded' => 'filament-notifications-max::mail.default',
    ]]);

    // Clean fixture with a deliberately bogus template name. Replacing
    // the whole `notifications` key (rather than dot-setting into it) so
    // the deep override is what the registry actually sees on flush().
    config(['notifications' => [
        'demo.bad-template' => [
            'title' => 'Top title',
            'body' => 'Top body',
            'content' => [
                'email' => [
                    'subject' => 'Subject {name}',
                    'body' => '<p>Body {name}</p>',
                    'template' => 'doesnt-exist',
                ],
            ],
            'default_channels' => ['email'],
            'allowed_channels' => ['email'],
        ],
    ]]);
    app(NotificationTypeRegistry::class)->flush();
    app(NotificationContentResolver::class)->flushCache();

    Log::spy();

    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.bad-template', ['name' => 'Frank']);

    $message = (new MailChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->markdown)->toBe('filament-notifications-max::mail.default');
    Log::shouldHaveReceived('warning')->once();
});

it('MailChannelHandler throws RuntimeException when the email_templates registry is empty', function (): void {
    // Hosts who explicitly empty the registry hit a loud failure rather
    // than a silently mangled mail. The shipped default means this only
    // fires when someone actively removed it.
    config(['notifications-max.email_templates' => []]);

    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.with.email', ['name' => 'Grace']);

    expect(fn () => (new MailChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification))
        ->toThrow(\RuntimeException::class, 'at least one email template must be registered');
});

it('MailChannelHandler HTML-escapes interpolated context values in the email body', function (): void {
    // Body template `<p>Email body {name}</p>` is trusted (admin-authored
    // HTML), but the substituted {name} value comes from untrusted
    // dispatch context — it must be escaped so it can't inject markup
    // into the rendered email.
    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.with.email', [
        'name' => '<script>alert(1)</script>',
    ]);

    $message = (new MailChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->viewData['content'])
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>');
});

it('MailChannelHandler passes the email subject through plain — no HTML escape', function (): void {
    // Mail transports header-encode the subject on the wire (RFC 5322).
    // We render with default 'plain' richness so a value like "A & B"
    // appears literally — not "A &amp; B".
    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.with.email', [
        'name' => 'Alice & Bob',
    ]);

    $message = (new MailChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->subject)->toBe('Email subject Alice & Bob');
});

it('default mail template renders the action button when actionText is set', function (): void {
    // Regression guard for the action-button bug: the package's default
    // template needs to opt in to rendering $actionText / $actionUrl
    // (which Laravel's MailChannel auto-merges into the view data from
    // the MailMessage's ->action() call). Render the template via the
    // Markdown facade so the mail:: component namespace resolves the same
    // way it would at real send time.
    $html = app(Markdown::class)->render(
        'filament-notifications-max::mail.default',
        [
            'subject' => 'Approval needed',
            'content' => '<p>Body</p>',
            'actionText' => 'Approve',
            'actionUrl' => 'https://example.test/approve/1',
        ],
    )->toHtml();

    expect($html)
        ->toContain('Approve')
        ->toContain('https://example.test/approve/1')
        // Laravel's mail::button component output wraps the link in a
        // table cell with class="button" — asserting the class catches
        // "URL happens to appear elsewhere in the page" false positives.
        ->toContain('class="button');
});

it('MailChannelHandler merges the full brand bag from the tenant — logo, logoDark, brand, brandUrl', function (): void {
    // Multi-tenant happy path. Tenant exposes the loose convention
    // methods + a `name` attribute; resolver populates every brand
    // field. The handler doesn't care that the tenant isn't an
    // Eloquent model — it only cares about the methods + name.
    $tenant = new class {
        public string $name = 'Acme Co.';

        public function getLogoUrl(): ?string
        {
            return 'https://tenant.test/logo.png';
        }

        public function getLogoDarkUrl(): ?string
        {
            return 'https://tenant.test/logo-dark.png';
        }

        public function getBrandUrl(): ?string
        {
            return 'https://acme.example.test';
        }
    };

    $notifiable = new class($tenant) {
        public function __construct(public readonly object $tenant) {}

        public function routeNotificationForMail(): string
        {
            return 'a@x.test';
        }
    };

    $notification = new GenericNotification('demo.with.email', ['name' => 'Eve']);

    $message = (new MailChannelHandler(app(NotificationContentResolver::class)))
        ->send($notifiable, $notification);

    expect($message->viewData)
        ->toMatchArray([
            'logo' => 'https://tenant.test/logo.png',
            'logoDark' => 'https://tenant.test/logo-dark.png',
            'brand' => 'Acme Co.',
            'brandUrl' => 'https://acme.example.test',
        ]);
});

it('MailChannelHandler fills tenant-missing fields from Filament panel brand', function (): void {
    // Tenant has only a name — no logo methods. Resolver should pick up
    // the brand name from the tenant but fall through to the Filament
    // panel for the logo. Independent fallback per field, not all-or-
    // nothing.
    Filament::shouldReceive('getTenant')->andReturn(null);
    Filament::shouldReceive('getBrandLogo')->andReturn('https://panel.test/logo.png');
    Filament::shouldReceive('getBrandName')->andReturn('Fallback Co.');

    $tenant = new class {
        public string $name = 'Acme Co.';
    };

    $notifiable = new class($tenant) {
        public function __construct(public readonly object $tenant) {}

        public function routeNotificationForMail(): string
        {
            return 'a@x.test';
        }
    };

    $notification = new GenericNotification('demo.with.email', ['name' => 'Eve']);

    $message = (new MailChannelHandler(app(NotificationContentResolver::class)))
        ->send($notifiable, $notification);

    // Brand name from tenant wins over Filament's getBrandName fallback.
    expect($message->viewData['brand'])->toBe('Acme Co.')
        ->and($message->viewData['logo'])->toBe('https://panel.test/logo.png')
        ->and($message->viewData)->not->toHaveKey('logoDark')
        ->and($message->viewData)->not->toHaveKey('brandUrl');
});

it('MailChannelHandler falls back to Filament panel brand when notifiable has no tenant', function (): void {
    // Single-tenant install (or notifiable without a tenant relation) —
    // the resolver pulls panel-level brand logo + name via Filament's
    // facade so hosts with `Panel::brandLogo(...)` / `brandName(...)`
    // configured see it in their emails with zero package config.
    Filament::shouldReceive('getTenant')->andReturn(null);
    Filament::shouldReceive('getBrandLogo')->andReturn('https://panel.test/logo.png');
    Filament::shouldReceive('getBrandName')->andReturn('Panel Co.');

    $notifiable = new class {
        public function routeNotificationForMail(): string
        {
            return 'a@x.test';
        }
    };

    $notification = new GenericNotification('demo.with.email', ['name' => 'Eve']);

    $message = (new MailChannelHandler(app(NotificationContentResolver::class)))
        ->send($notifiable, $notification);

    expect($message->viewData['logo'])->toBe('https://panel.test/logo.png')
        ->and($message->viewData['brand'])->toBe('Panel Co.');
});

it('MailChannelHandler omits logo when Filament returns an HTML/Blade brand logo', function (): void {
    // `Panel::brandLogo()` accepts string|Htmlable|null. The mail header
    // takes a URL for `<img src>`; an Htmlable can't be inlined safely
    // here, so the logo key drops out — but the brand NAME still lands
    // in view data so the theme's text fallback renders.
    Filament::shouldReceive('getTenant')->andReturn(null);
    Filament::shouldReceive('getBrandLogo')->andReturn(new HtmlString('<svg>...</svg>'));
    Filament::shouldReceive('getBrandName')->andReturn('Panel Co.');

    $notifiable = new class {
        public function routeNotificationForMail(): string
        {
            return 'a@x.test';
        }
    };

    $notification = new GenericNotification('demo.with.email', ['name' => 'Eve']);

    $message = (new MailChannelHandler(app(NotificationContentResolver::class)))
        ->send($notifiable, $notification);

    expect($message->viewData)->not->toHaveKey('logo')
        ->and($message->viewData['brand'])->toBe('Panel Co.');
});

it('MailChannelHandler absolutizes root-relative URLs in brand fields using config(app.url)', function (): void {
    // Real-world case: Spatie Media Library returns `/storage/x.png` for
    // the `public` disk by default. Mail recipients can't resolve a
    // root-relative path — the handler must prepend the app URL so the
    // image actually loads in Gmail / Outlook / mobile clients. URLs
    // that already have a scheme are passed through unchanged so CDN
    // and protocol-relative URLs survive intact.
    config(['app.url' => 'https://myapp.test']);

    $tenant = new class {
        public string $name = 'Acme Co.';

        public function getLogoUrl(): string
        {
            return '/storage/19/logo.png'; // root-relative, must be absolutized
        }

        public function getLogoDarkUrl(): string
        {
            return 'https://cdn.example/dark.png'; // already absolute, leave alone
        }
    };

    $notifiable = new class($tenant) {
        public function __construct(public readonly object $tenant) {}

        public function routeNotificationForMail(): string
        {
            return 'a@x.test';
        }
    };

    $notification = new GenericNotification('demo.with.email', ['name' => 'Eve']);

    $message = (new MailChannelHandler(app(NotificationContentResolver::class)))
        ->send($notifiable, $notification);

    expect($message->viewData['logo'])->toBe('https://myapp.test/storage/19/logo.png')
        ->and($message->viewData['logoDark'])->toBe('https://cdn.example/dark.png');
});

it('MailChannelHandler returns an empty brand bag when neither tenant nor Filament has anything', function (): void {
    // Bare install — no tenant relation, no panel brand. Every brand
    // key drops out so the mail theme's hard-coded fallback (e.g.
    // `config('app.name')` in Orbit) renders.
    Filament::shouldReceive('getTenant')->andReturn(null);
    Filament::shouldReceive('getBrandLogo')->andReturn(null);
    Filament::shouldReceive('getBrandName')->andReturn(null);

    $notifiable = new class {
        public function routeNotificationForMail(): string
        {
            return 'a@x.test';
        }
    };

    $notification = new GenericNotification('demo.with.email', ['name' => 'Eve']);

    $message = (new MailChannelHandler(app(NotificationContentResolver::class)))
        ->send($notifiable, $notification);

    expect($message->viewData)->not->toHaveKey('logo')
        ->and($message->viewData)->not->toHaveKey('logoDark')
        ->and($message->viewData)->not->toHaveKey('brand')
        ->and($message->viewData)->not->toHaveKey('brandUrl');
});

it('default mail template omits the action button when actionText is not set', function (): void {
    // Informational notifications (no CTA) shouldn't get an empty button
    // rendered in the email. The @isset guard in the template enforces
    // that.
    $html = app(Markdown::class)->render(
        'filament-notifications-max::mail.default',
        [
            'subject' => 'Records locked',
            'content' => '<p>Body</p>',
        ],
    )->toHtml();

    expect($html)->not->toContain('class="button');
});
