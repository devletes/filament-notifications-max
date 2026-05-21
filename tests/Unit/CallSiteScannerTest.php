<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Console\TypeDiscovery\CallSiteScanner;
use Devletes\NotificationsMax\Console\TypeDiscovery\DiscoveredCallSite;

beforeEach(function (): void {
    $this->scanner = new CallSiteScanner;
    $this->scanDir = sys_get_temp_dir().'/notifications-max-scanner-'.bin2hex(random_bytes(4));
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

function writeFixture(string $dir, string $name, string $contents): string
{
    $path = $dir.'/'.$name;
    file_put_contents($path, $contents);

    return $path;
}

it('discovers an app(NotificationDispatcher::class) call with literal type and context', function (): void {
    writeFixture($this->scanDir, 'TaskService.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class TaskService {
    public function createTask(): void {
        app(NotificationDispatcher::class)->send('task.created', ['subject_id' => 1, 'actor_name' => 'Alice'], 1);
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(1)
        ->and($sites[0])->toBeInstanceOf(DiscoveredCallSite::class)
        ->and($sites[0]->typeKey)->toBe('task.created')
        ->and($sites[0]->contextKeys)->toBe(['subject_id', 'actor_name'])
        ->and($sites[0]->sourceFile)->toEndWith('TaskService.php');
});

it('discovers a constructor-injected dispatcher used via $this->property', function (): void {
    writeFixture($this->scanDir, 'OrderService.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class OrderService {
    public function __construct(protected NotificationDispatcher $dispatcher) {}
    public function placeOrder(): void {
        $this->dispatcher->send('order.placed', ['order_id' => 42], 1);
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->typeKey)->toBe('order.placed')
        ->and($sites[0]->contextKeys)->toBe(['order_id']);
});

it('discovers schedule() and uses the second arg as the type key', function (): void {
    writeFixture($this->scanDir, 'Scheduler.php', <<<'PHP'
<?php
namespace App\Services;
use DateTimeInterface;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class Scheduler {
    public function schedule(DateTimeInterface $at): void {
        app(NotificationDispatcher::class)->schedule($at, 'reminder.daily', ['user_id' => 1], 1);
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->typeKey)->toBe('reminder.daily')
        ->and($sites[0]->contextKeys)->toBe(['user_id']);
});

it('records a dynamic call site when the type key is not a string literal', function (): void {
    writeFixture($this->scanDir, 'Dynamic.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class Dynamic {
    public function go(string $key): void {
        app(NotificationDispatcher::class)->send($key, [], 1);
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->isDynamic())->toBeTrue()
        ->and($sites[0]->typeKey)->toBeNull();
});

it('resolves aliased imports of the dispatcher', function (): void {
    writeFixture($this->scanDir, 'Aliased.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher as Notifier;
class Aliased {
    public function go(): void {
        app(Notifier::class)->send('user.welcomed', ['name' => 'Alice'], 1);
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->typeKey)->toBe('user.welcomed');
});

it('discovers via regular (non-promoted) property declarations', function (): void {
    writeFixture($this->scanDir, 'Audit.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class Audit {
    protected NotificationDispatcher $dispatcher;
    public function __construct(NotificationDispatcher $d) { $this->dispatcher = $d; }
    public function log(): void {
        $this->dispatcher->send('audit.logged', ['action' => 'create'], 1);
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->typeKey)->toBe('audit.logged');
});

it('ignores send() calls on unrelated classes that happen to share the name', function (): void {
    writeFixture($this->scanDir, 'Unrelated.php', <<<'PHP'
<?php
namespace App\Services;
// Reference to NotificationDispatcher exists in a use line below the
// pass-1 grep would match the file, but the actual send() call here
// is on a different object — should NOT be captured.
use Devletes\NotificationsMax\Services\NotificationDispatcher;
use Illuminate\Support\Facades\Notification;
class Unrelated {
    public function go(): void {
        Notification::send([], new \stdClass);
    }
    public function unused(NotificationDispatcher $d): void {}
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(0);
});

it('returns nothing for files without any NotificationDispatcher reference', function (): void {
    writeFixture($this->scanDir, 'PlainService.php', <<<'PHP'
<?php
namespace App\Services;
class PlainService {
    public function go(): void { /* no notifications */ }
}
PHP);

    expect($this->scanner->scan($this->scanDir))->toBe([]);
});

it('captures multiple call sites for the same type across one file', function (): void {
    writeFixture($this->scanDir, 'Multi.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class Multi {
    public function a(): void {
        app(NotificationDispatcher::class)->send('task.created', ['subject_id' => 1], 1);
    }
    public function b(): void {
        app(NotificationDispatcher::class)->send('task.created', ['subject_id' => 2, 'extra' => 'x'], 1);
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(2)
        ->and($sites[0]->typeKey)->toBe('task.created')
        ->and($sites[1]->typeKey)->toBe('task.created')
        ->and($sites[0]->sourceLine)->not->toBe($sites[1]->sourceLine);
});

it('returns an empty list when the scan directory does not exist', function (): void {
    expect($this->scanner->scan($this->scanDir.'/nope'))->toBe([]);
});

it('skips files with PHP syntax errors instead of failing the run', function (): void {
    writeFixture($this->scanDir, 'Broken.php', '<?php class { syntax error here NotificationDispatcher');
    writeFixture($this->scanDir, 'Good.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class Good {
    public function go(): void {
        app(NotificationDispatcher::class)->send('foo.bar', [], 1);
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->typeKey)->toBe('foo.bar');
});

it('discovers calls that pass typeKey as a named argument', function (): void {
    writeFixture($this->scanDir, 'NamedArg.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class NamedArg {
    public function go(): void {
        app(NotificationDispatcher::class)->send(
            typeKey: 'survey.cycle.closing_soon',
            context: ['cycle_id' => 1, 'days_left' => 3],
            recipients: 1,
        );
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->typeKey)->toBe('survey.cycle.closing_soon')
        ->and($sites[0]->contextKeys)->toBe(['cycle_id', 'days_left']);
});

it('handles a mix of positional and named arguments', function (): void {
    writeFixture($this->scanDir, 'Mixed.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class Mixed {
    public function go(): void {
        app(NotificationDispatcher::class)->send('foo.bar', context: ['x' => 1], recipients: 1);
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->typeKey)->toBe('foo.bar')
        ->and($sites[0]->contextKeys)->toBe(['x']);
});

it('resolves schedule() with named arguments', function (): void {
    writeFixture($this->scanDir, 'ScheduledNamed.php', <<<'PHP'
<?php
namespace App\Services;
use DateTimeInterface;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class ScheduledNamed {
    public function go(DateTimeInterface $at): void {
        app(NotificationDispatcher::class)->schedule(
            delayUntil: $at,
            typeKey: 'reminder.weekly',
            context: ['user_id' => 1],
            recipients: 1,
        );
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->typeKey)->toBe('reminder.weekly')
        ->and($sites[0]->contextKeys)->toBe(['user_id']);
});

it('tracks a local variable assigned from app(NotificationDispatcher::class)', function (): void {
    writeFixture($this->scanDir, 'LocalApp.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class LocalApp {
    public function go(): void {
        $dispatcher = app(NotificationDispatcher::class);
        $dispatcher->send('pulse.published', ['post_id' => 1], 1);
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->typeKey)->toBe('pulse.published')
        ->and($sites[0]->contextKeys)->toBe(['post_id']);
});

it('tracks a local variable assigned from resolve()', function (): void {
    writeFixture($this->scanDir, 'LocalResolve.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class LocalResolve {
    public function go(): void {
        $d = resolve(NotificationDispatcher::class);
        $d->send('via.resolve', [], 1);
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->typeKey)->toBe('via.resolve');
});

it('tracks a local variable assigned from App::make()', function (): void {
    writeFixture($this->scanDir, 'LocalAppMake.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
use Illuminate\Support\Facades\App;
class LocalAppMake {
    public function go(): void {
        $d = App::make(NotificationDispatcher::class);
        $d->send('via.appmake', [], 1);
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->typeKey)->toBe('via.appmake');
});

it('tracks dispatcher passed as a typed method parameter', function (): void {
    writeFixture($this->scanDir, 'TypedParam.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class TypedParam {
    public function handle(NotificationDispatcher $notifications): void {
        $notifications->send('handler.fired', ['x' => 1], 1);
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->typeKey)->toBe('handler.fired');
});

it('does not leak a tracked variable between sibling methods', function (): void {
    writeFixture($this->scanDir, 'Isolation.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class Isolation {
    public function withDispatcher(): void {
        $d = app(NotificationDispatcher::class);
        $d->send('first.method', [], 1);
    }
    public function withoutDispatcher(): void {
        $d = new \stdClass;
        $d->send('second.method', [], 1);  // $d here is NOT a dispatcher — must be skipped
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->typeKey)->toBe('first.method');
});

it('forwards dispatcher captures into closures via use clauses', function (): void {
    writeFixture($this->scanDir, 'ClosureUse.php', <<<'PHP'
<?php
namespace App\Services;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
class ClosureUse {
    public function go(): void {
        $d = app(NotificationDispatcher::class);
        $cb = function () use ($d) {
            $d->send('closure.fired', [], 1);
        };
        $cb();
    }
}
PHP);

    $sites = $this->scanner->scan($this->scanDir);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->typeKey)->toBe('closure.fired');
});
