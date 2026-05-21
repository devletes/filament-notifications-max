<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->scanDir = sys_get_temp_dir().'/notifications-max-cmd-'.bin2hex(random_bytes(4));
    mkdir($this->scanDir, 0777, true);
});

afterEach(function (): void {
    if (is_dir($this->scanDir)) {
        foreach (glob($this->scanDir.'/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->scanDir);
    }
});

function fixture(string $dir, string $name, string $contents): void
{
    file_put_contents($dir.'/'.$name, $contents);
}

it('prints generated entries with --print-only and never writes the config file', function (): void {
    fixture($this->scanDir, 'Service.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class Service {
    public function go(): void {
        app(NotificationDispatcher::class)->send('task.created', ['subject_id' => 1, 'actor' => 'a'], 1);
    }
}
PHP);

    $this->artisan('notifications-max:generate-types', [
        '--path' => $this->scanDir,
        '--print-only' => true,
    ])
        ->expectsOutputToContain("'task.created' => [")
        ->expectsOutputToContain("'category' => 'task'")
        ->expectsOutputToContain('Expected context: subject_id, actor')
        ->assertSuccessful();
});

it('reports skipped dynamic call sites separately', function (): void {
    fixture($this->scanDir, 'Dynamic.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class Dynamic {
    public function go(string $key): void {
        app(NotificationDispatcher::class)->send($key, [], 1);
    }
}
PHP);

    $this->artisan('notifications-max:generate-types', [
        '--path' => $this->scanDir,
        '--print-only' => true,
    ])
        ->expectsOutputToContain('Skipped 1 call with a non-literal type key')
        ->assertSuccessful();
});

it('honors --type to limit which keys are generated', function (): void {
    fixture($this->scanDir, 'Multi.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class Multi {
    public function a(): void {
        app(NotificationDispatcher::class)->send('task.created', [], 1);
    }
    public function b(): void {
        app(NotificationDispatcher::class)->send('order.placed', [], 1);
    }
}
PHP);

    $this->artisan('notifications-max:generate-types', [
        '--path' => $this->scanDir,
        '--type' => 'task.created',
        '--print-only' => true,
    ])
        ->expectsOutputToContain("'task.created' => [")
        ->doesntExpectOutputToContain("'order.placed' => [")
        ->assertSuccessful();
});

it('treats --type as a denylist when --exclude is passed', function (): void {
    fixture($this->scanDir, 'Multi.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class Multi {
    public function a(): void {
        app(NotificationDispatcher::class)->send('task.created', [], 1);
    }
    public function b(): void {
        app(NotificationDispatcher::class)->send('order.placed', [], 1);
    }
}
PHP);

    $this->artisan('notifications-max:generate-types', [
        '--path' => $this->scanDir,
        '--type' => 'task.created',
        '--exclude' => true,
        '--print-only' => true,
    ])
        ->doesntExpectOutputToContain("'task.created' => [")
        ->expectsOutputToContain("'order.placed' => [")
        ->assertSuccessful();
});

it('reports cleanly when no dispatch calls are found', function (): void {
    fixture($this->scanDir, 'Empty.php', '<?php namespace App; class Empty1 {}');

    $this->artisan('notifications-max:generate-types', [
        '--path' => $this->scanDir,
    ])
        ->expectsOutputToContain('No NotificationDispatcher::send() / ::schedule() calls found')
        ->assertSuccessful();
});

it('errors out when the scan path does not exist', function (): void {
    $this->artisan('notifications-max:generate-types', [
        '--path' => $this->scanDir.'/missing-subdir',
    ])
        ->expectsOutputToContain('Scan path does not exist')
        ->assertFailed();
});

it('falls back to print-only when types_config_key is nested', function (): void {
    config(['notifications-max.types_config_key' => 'app-events.types']);

    fixture($this->scanDir, 'Service.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class Service {
    public function go(): void {
        app(NotificationDispatcher::class)->send('foo.bar', [], 1);
    }
}
PHP);

    $this->artisan('notifications-max:generate-types', [
        '--path' => $this->scanDir,
    ])
        ->expectsOutputToContain('nested path')
        ->assertFailed();
});
