<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Devletes\NotificationsMax\Services\NotificationContentResolver;

beforeEach(function (): void {
    config(['notifications' => [
        'demo.type' => [
            'title' => 'Demo',
            'body' => 'Body',
            'default_channels' => ['push'],
            'allowed_channels' => ['push'],
        ],
    ]]);

    app(NotificationTypeRegistry::class)->flush();
});

it('render() defaults to plain richness — interpolated value passes through verbatim (regression)', function (): void {
    // The historical contract (pre-richness): substituted scalars come
    // out untouched. Locks in that adding the new arg doesn't shift
    // behaviour for callers that never specify it.
    $n = new GenericNotification('demo.type', ['name' => '<script>alert(1)</script>']);

    expect($n->render('Hello {name}'))->toBe('Hello <script>alert(1)</script>');
});

it('render(html) HTML-escapes interpolated context values', function (): void {
    $n = new GenericNotification('demo.type', ['name' => '<script>alert(1)</script>']);

    expect($n->render('Hello {name}', 'html'))
        ->toBe('Hello &lt;script&gt;alert(1)&lt;/script&gt;');
});

it('render(html) leaves the template untouched — only the interpolated value is escaped', function (): void {
    // Templates are trusted (admin-authored). An admin writing
    // `<strong>` in a rich-text body wants the bold tag rendered.
    $n = new GenericNotification('demo.type', ['name' => 'Alice & Bob']);

    expect($n->render('<strong>Hi {name}</strong>', 'html'))
        ->toBe('<strong>Hi Alice &amp; Bob</strong>');
});

it('render(html) escapes quotes in interpolated values too', function (): void {
    // Defence against attribute-context XSS in case the template ever
    // embeds a value inside attributes (e.g. `<a title="{label}">`).
    $n = new GenericNotification('demo.type', ['label' => 'He said "hi"']);

    expect($n->render('<a title="{label}">x</a>', 'html'))
        ->toBe('<a title="He said &quot;hi&quot;">x</a>');
});

it('render(markdown) HTML-entity-encodes &, <, > in interpolated values (Slack convention)', function (): void {
    // Slack's docs require HTML-entity escaping of `&`, `<`, `>` in any
    // user-supplied text — otherwise `<url>` is parsed as a link.
    $n = new GenericNotification('demo.type', ['summary' => 'a & b < c > d']);

    expect($n->render('Summary: {summary}', 'markdown'))
        ->toBe('Summary: a &amp; b &lt; c &gt; d');
});

it('render(markdown) backslash-escapes Slack mrkdwn formatting characters in values', function (): void {
    // A value containing `*foo*` would otherwise trigger bold formatting
    // after substitution. Backslash escapes neutralise it without
    // breaking the surrounding template.
    $n = new GenericNotification('demo.type', ['title' => '*foo* _bar_ ~baz~ `code`']);

    expect($n->render('Title: {title}', 'markdown'))
        ->toBe('Title: \\*foo\\* \\_bar\\_ \\~baz\\~ \\`code\\`');
});

it('render(markdown) does not touch formatting characters in the template itself', function (): void {
    // Same trust contract as html — the template is authored content,
    // only context values are sanitized.
    $n = new GenericNotification('demo.type', ['name' => 'Alice']);

    expect($n->render('*Hello {name}*', 'markdown'))->toBe('*Hello Alice*');
});

it('render(markdown) escapes literal backslashes in values before other characters', function (): void {
    // Order matters: backslash must be escaped first so the escape
    // character itself doesn't double-process subsequent additions.
    $n = new GenericNotification('demo.type', ['path' => 'C:\\Users\\x']);

    expect($n->render('Path: {path}', 'markdown'))
        ->toBe('Path: C:\\\\Users\\\\x');
});

it('render() falls back to plain for unknown richness values', function (): void {
    // Defensive default: a host adding a new channel with a typo'd
    // richness shouldn't get HTML/markdown escaping applied. Plain is
    // the safe fallback because it never modifies the value.
    $n = new GenericNotification('demo.type', ['name' => '<x>']);

    expect($n->render('Hi {name}', 'totally-unknown-richness'))->toBe('Hi <x>');
});

it('render() preserves missing placeholders regardless of richness', function (): void {
    // Missing-key behaviour (leave the placeholder intact) shouldn't be
    // affected by richness — it's a debugging affordance, not a
    // substituted value.
    $n = new GenericNotification('demo.type', []);

    expect($n->render('Hello {missing}', 'html'))->toBe('Hello {missing}');
    expect($n->render('Hello {missing}', 'markdown'))->toBe('Hello {missing}');
});

it('NotificationContentResolver::richnessFor() returns the declared richness for a channel', function (): void {
    $resolver = app(NotificationContentResolver::class);

    // Push is plain — admins author plain text, channel handler bridges
    // to Filament's HTML render surface via e(). Email is html — admin
    // writes HTML in the rich editor and it's trusted.
    expect($resolver->richnessFor('push'))->toBe('plain');
    expect($resolver->richnessFor('email'))->toBe('html');
});

it('NotificationContentResolver::richnessFor() defaults to plain for channels without a declaration', function (): void {
    // Host-added channels predating the richness key — or anything that
    // forgets to declare it — fall back to plain so the package never
    // accidentally treats values as HTML on a misconfigured channel.
    config(['notifications-max.channels.custom' => [
        'label' => 'Custom',
        'physical' => ['custom'],
        'content_fields' => ['body' => 'text'],
        // richness intentionally omitted
    ]]);

    $resolver = app(NotificationContentResolver::class);

    expect($resolver->richnessFor('custom'))->toBe('plain');
});

it('NotificationContentResolver::richnessFor() defaults to plain for unknown channels', function (): void {
    $resolver = app(NotificationContentResolver::class);

    expect($resolver->richnessFor('this-channel-does-not-exist'))->toBe('plain');
});
