<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Console\TypeDiscovery\TypeConfigWriter;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->writer = new TypeConfigWriter(new Filesystem);
    $this->configPath = sys_get_temp_dir().'/notifications-max-writer-'.bin2hex(random_bytes(4)).'.php';
});

afterEach(function (): void {
    @unlink($this->configPath);
    @unlink($this->configPath.'.bak');
});

it('creates a fresh scaffold when the config file does not exist', function (): void {
    $entries = [
        'task.created' => "    'task.created' => [\n        'category' => 'task',\n    ],",
    ];

    $result = $this->writer->write($this->configPath, $entries);

    expect($result->wasCreated)->toBeTrue()
        ->and($result->wasWritten)->toBeTrue()
        ->and($result->addedKeys)->toBe(['task.created'])
        ->and(file_exists($this->configPath))->toBeTrue();

    $written = require $this->configPath;

    expect($written)->toBeArray()
        ->and($written)->toHaveKey('task.created');
});

it('appends new entries to an existing config file before the final ];', function (): void {
    file_put_contents($this->configPath, <<<'PHP'
<?php

return [
    'existing.type' => [
        'category' => 'general',
    ],
];

PHP);

    $entries = [
        'task.created' => "    'task.created' => [\n        'category' => 'task',\n    ],",
    ];

    $result = $this->writer->write($this->configPath, $entries);

    expect($result->wasCreated)->toBeFalse()
        ->and($result->wasWritten)->toBeTrue()
        ->and($result->addedKeys)->toBe(['task.created']);

    $written = require $this->configPath;

    expect($written)->toHaveKeys(['existing.type', 'task.created']);
});

it('skips type keys that already exist in the config (merge mode)', function (): void {
    file_put_contents($this->configPath, <<<'PHP'
<?php

return [
    'task.created' => [
        'category' => 'task',
        'label' => 'Hand-tuned label',
    ],
];

PHP);

    $entries = [
        'task.created' => "    'task.created' => [\n        'category' => 'task',\n    ],",
        'order.placed' => "    'order.placed' => [\n        'category' => 'order',\n    ],",
    ];

    $result = $this->writer->write($this->configPath, $entries);

    expect($result->addedKeys)->toBe(['order.placed'])
        ->and($result->skippedKeys)->toBe(['task.created']);

    $written = require $this->configPath;

    expect($written['task.created']['label'])->toBe('Hand-tuned label')
        ->and($written)->toHaveKey('order.placed');
});

it('does not write the file when there is nothing to add', function (): void {
    file_put_contents($this->configPath, <<<'PHP'
<?php

return [
    'task.created' => [
        'category' => 'task',
    ],
];

PHP);

    $originalContents = file_get_contents($this->configPath);

    $result = $this->writer->write($this->configPath, [
        'task.created' => "    'task.created' => [\n    ],",
    ]);

    expect($result->wasWritten)->toBeFalse()
        ->and($result->addedKeys)->toBe([])
        ->and($result->skippedKeys)->toBe(['task.created'])
        ->and(file_get_contents($this->configPath))->toBe($originalContents);
});

it('overwrites existing entries when force is true (relies on PHP last-wins)', function (): void {
    file_put_contents($this->configPath, <<<'PHP'
<?php

return [
    'task.created' => [
        'category' => 'old',
    ],
];

PHP);

    $entries = [
        'task.created' => "    'task.created' => [\n        'category' => 'new',\n    ],",
    ];

    $result = $this->writer->write($this->configPath, $entries, force: true);

    expect($result->addedKeys)->toBe(['task.created'])
        ->and($result->skippedKeys)->toBe([]);

    $written = require $this->configPath;

    expect($written['task.created']['category'])->toBe('new');
});

it('backs up the original file with a .bak suffix before patching', function (): void {
    file_put_contents($this->configPath, "<?php\n\nreturn [\n];\n");

    $original = file_get_contents($this->configPath);

    $this->writer->write($this->configPath, [
        'foo.bar' => "    'foo.bar' => [],",
    ]);

    expect(file_exists($this->configPath.'.bak'))->toBeTrue()
        ->and(file_get_contents($this->configPath.'.bak'))->toBe($original);
});

it('previews entries as a concatenated snippet without touching the filesystem', function (): void {
    $snippet = $this->writer->preview([
        'a' => "    'a' => [],",
        'b' => "    'b' => [],",
    ]);

    expect($snippet)->toContain("'a' => []")
        ->and($snippet)->toContain("'b' => []");
});
