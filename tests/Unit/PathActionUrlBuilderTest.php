<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Defaults\PathActionUrlBuilder;

beforeEach(function (): void {
    config(['app.url' => 'https://app.example.test']);

    $this->builder = new PathActionUrlBuilder;
});

it('builds a path-style URL from panel id, resource slug, and record id', function (): void {
    // No panel registered with this id — builder uses the id as the path.
    $url = $this->builder->build('admin', 'requests', 42);

    expect($url)->toBe('https://app.example.test/admin/requests/42');
});

it('handles a string record id', function (): void {
    $url = $this->builder->build('admin', 'requests', 'abc-123');

    expect($url)->toBe('https://app.example.test/admin/requests/abc-123');
});

it('strips trailing slash from app.url', function (): void {
    config(['app.url' => 'https://app.example.test/']);

    $url = $this->builder->build('admin', 'requests', 42);

    expect($url)->toBe('https://app.example.test/admin/requests/42');
});

it('returns the base URL when path components are all empty', function (): void {
    // resourceSlug empty + recordId empty — array_filter strips both.
    $url = $this->builder->build('', '', '');

    expect($url)->toBe('https://app.example.test');
});

it('emits the table-action query form when context carries table_action', function (): void {
    $url = $this->builder->build('employee', 'tasks', 42, ['table_action' => 'view']);

    expect($url)->toBe('https://app.example.test/employee/tasks?tableAction=view&tableActionRecord=42');
});

it('table-action query form handles a string record id', function (): void {
    $url = $this->builder->build('admin', 'documents', 'doc-abc', ['table_action' => 'view']);

    expect($url)->toBe('https://app.example.test/admin/documents?tableAction=view&tableActionRecord=doc-abc');
});

it('keeps the record path form when table_action is absent or empty', function (): void {
    // Regression — the query branch must not fire for legacy contexts.
    expect($this->builder->build('admin', 'requests', 42, []))
        ->toBe('https://app.example.test/admin/requests/42')
        ->and($this->builder->build('admin', 'requests', 42, ['table_action' => '']))
        ->toBe('https://app.example.test/admin/requests/42');
});
