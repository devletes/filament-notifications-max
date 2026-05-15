<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Tests;

use Devletes\NotificationsMax\NotificationsMaxServiceProvider;
use Devletes\NotificationsMax\Tests\Stubs\User;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Support\SupportServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Custom users table (with tenant_id + phone columns we test against)
        // plus Laravel's stock notifications table and the package's own
        // migration stubs. Stubs are valid PHP — we require() them so they
        // run as anonymous migrations against the in-memory database.
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        foreach (glob(__DIR__.'/../database/migrations/*.php.stub') as $stub) {
            (require $stub)->up();
        }
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            // Filament's bootstrap chain — Livewire is required by the
            // panel provider (Filament resources / pages are Livewire
            // components). Support + Notifications give us the
            // `Filament\Notifications\Notification` builder used by
            // GenericNotification::buildFilamentPayload. The Panels
            // provider exposes the `Filament` facade for tenant lookups.
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            NotificationsServiceProvider::class,
            FilamentServiceProvider::class,
            NotificationsMaxServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $app['config']->set('auth.providers.users.model', User::class);

        // Force the tenant observer to register at boot. In production
        // `multi_tenant` defaults to 'auto' which probes the schema, but
        // in tests the schema check runs BEFORE setUp's migrations land,
        // so 'auto' resolves to false and the observer never attaches.
        // Tests that don't touch the observer are unaffected.
        $app['config']->set('notifications-max.multi_tenant', true);

        // Minimal notification type registry. Individual tests override
        // this via config() to exercise specific shapes.
        $app['config']->set('notifications', [
            'test.simple' => [
                'category' => 'general',
                'label' => 'Simple test type',
                'title' => 'Hello {name}',
                'body' => 'Body for {name}',
                'icon' => 'heroicon-o-bell',
                'default_channels' => ['push'],
                'allowed_channels' => ['push', 'email'],
            ],
            'test.mandatory' => [
                'category' => 'general',
                'label' => 'Mandatory test type',
                'title' => 'Required',
                'body' => 'Cannot be silenced',
                'default_channels' => ['push'],
                'allowed_channels' => ['push', 'email'],
                'mandatory' => true,
            ],
        ]);
    }
}
