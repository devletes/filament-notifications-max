<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Http\Controllers\NotificationRedirectController;

it('matches the most specific panel path against the referer', function (): void {
    $result = NotificationRedirectController::matchRefererToPanel(
        referer: 'http://localhost/employee/notifications',
        currentHost: 'localhost',
        panelPaths: ['admin' => '', 'employee' => 'employee'],
    );

    expect($result)->toBe('employee');
});

it('falls through to a root-mounted panel when no nested path matches', function (): void {
    $result = NotificationRedirectController::matchRefererToPanel(
        referer: 'http://localhost/dashboard',
        currentHost: 'localhost',
        panelPaths: ['admin' => '', 'employee' => 'employee'],
    );

    // /dashboard doesn't match /employee → admin's empty path catches it.
    expect($result)->toBe('admin');
});

it('returns null on a cross-origin referer', function (): void {
    $result = NotificationRedirectController::matchRefererToPanel(
        referer: 'http://attacker.example.com/employee/x',
        currentHost: 'localhost',
        panelPaths: ['admin' => '', 'employee' => 'employee'],
    );

    expect($result)->toBeNull();
});

it('returns null when no panel matches and there is no root-mounted catch-all', function (): void {
    $result = NotificationRedirectController::matchRefererToPanel(
        referer: 'http://localhost/somewhere-else',
        currentHost: 'localhost',
        panelPaths: ['admin' => 'admin', 'employee' => 'employee'],
    );

    expect($result)->toBeNull();
});

it('does not partial-match: /employee-team must not match /employee panel', function (): void {
    // A panel mounted at /employee should NOT catch /employee-team — that
    // would route an unrelated path to the wrong panel.
    $result = NotificationRedirectController::matchRefererToPanel(
        referer: 'http://localhost/employee-team/notifications',
        currentHost: 'localhost',
        panelPaths: ['admin' => '', 'employee' => 'employee'],
    );

    // Should fall through to admin's catch-all.
    expect($result)->toBe('admin');
});

it('matches exact path equal to the panel path', function (): void {
    $result = NotificationRedirectController::matchRefererToPanel(
        referer: 'http://localhost/employee',
        currentHost: 'localhost',
        panelPaths: ['admin' => '', 'employee' => 'employee'],
    );

    expect($result)->toBe('employee');
});

it('returns null on a malformed referer with no host', function (): void {
    $result = NotificationRedirectController::matchRefererToPanel(
        referer: 'not-a-url',
        currentHost: 'localhost',
        panelPaths: ['admin' => ''],
    );

    expect($result)->toBeNull();
});
