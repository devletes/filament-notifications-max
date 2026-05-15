<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

/**
 * Stub Eloquent model for tests that need to bind a tenant via Filament's
 * facade. Filament::setTenant() type-hints `?Model`, so anonymous classes
 * won't satisfy it.
 *
 * No migration backs this — tests instantiate it in-memory and set
 * attributes via `forceFill()` rather than persisting.
 */
class Tenant extends Model
{
    protected $table = 'tenants';

    protected $guarded = [];

    public $timestamps = false;
}
