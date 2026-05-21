<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\User;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Point Laravel's auth at the Workbench user model. Default is
        // App\Models\User which doesn't exist in this package context.
        config(['auth.providers.users.model' => User::class]);

        // Pin the application URL so PathActionUrlBuilder produces stable
        // URLs across `testbench serve` runs (which default APP_URL to
        // http://localhost:8000). This keeps the redirect URL host
        // matching the request host so the controller's Referer check
        // succeeds during multi-panel click tests.
        config(['app.url' => 'http://localhost:8000']);

        // Disable broadcasting entirely. Filament's `echo.js` (loaded by
        // every panel) otherwise opens a Reverb/Pusher WebSocket on page
        // load and retries forever when no server is reachable — the
        // dangling reconnect loop keeps the headless renderer in a
        // "page busy" state and breaks `preview_screenshot`.
        config([
            'broadcasting.default' => 'null',
            'broadcasting.connections.null' => ['driver' => 'null'],
        ]);

        // Sample notification type registry, mirroring the multi-panel
        // shape we want to validate end-to-end:
        //
        //   - announce.system    → admin only
        //   - task.due           → employee only
        //   - shoutout.received  → cross-cutting (both panels)
        //
        // Each type carries `panels` so the user-prefs filter has
        // something to operate on. `action_resource` is set so a click
        // address can be synthesised from registry alone (no host code
        // needed in this stub).
        config(['notifications' => [
            'announce.system' => [
                'category' => 'announcements',
                'label' => 'System announcement',
                'description' => 'Posted by an administrator.',
                'title' => 'System: {headline}',
                'body' => '{body}',
                'icon' => 'heroicon-o-megaphone',
                'color' => 'primary',
                'panels' => ['admin'],
                'target_panel' => 'admin',
                'action_resource' => 'announcements',
                'action_record_key' => 'announcement_id',
                'default_channels' => ['push'],
                'allowed_channels' => ['push', 'email'],
            ],
            'task.due' => [
                'category' => 'tasks',
                'label' => 'Task due',
                'description' => 'A task assigned to you is due soon.',
                'title' => 'Task due: {task_title}',
                'body' => 'Due {due_at_relative}',
                'icon' => 'heroicon-o-clipboard-document-list',
                'panels' => ['employee'],
                'target_panel' => 'employee',
                'action_resource' => 'tasks',
                'action_record_key' => 'task_id',
                'default_channels' => ['push'],
                'allowed_channels' => ['push', 'email'],
            ],
            'shoutout.received' => [
                'category' => 'recognition',
                'label' => 'Shoutout received',
                'description' => 'Someone gave you a shoutout.',
                'title' => 'Shoutout from {from_name}',
                'body' => '{message}',
                'icon' => 'heroicon-o-hand-thumb-up',
                'color' => 'success',
                // Visible on both panels — admin and employee can both
                // act on this record. The redirect controller picks the
                // current panel; mail falls through to target_panel.
                'panels' => ['admin', 'employee'],
                'target_panel' => 'employee',
                'action_resource' => 'shoutouts',
                'action_record_key' => 'shoutout_id',
                'default_channels' => ['push'],
                'allowed_channels' => ['push', 'email'],
            ],
        ]]);
    }

    public function boot(): void
    {
        // Pin the URL root so `route()` produces stable absolute URLs
        // regardless of whether we're inside a request (browser hit) or
        // outside one (seeder, CLI command). Without this, CLI runs
        // default to "http://localhost" — which then mismatches the
        // 127.0.0.1:8000 the test server is bound to and breaks bell
        // clicks against seeded notifications.
        URL::forceRootUrl(config('app.url'));

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Workbench\App\Console\DumpSeedCommand::class,
            ]);
        }
    }
}
