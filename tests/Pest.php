<?php

/*
|--------------------------------------------------------------------------
| Pest configuration
|--------------------------------------------------------------------------
|
| Wires our package TestCase to every test under tests/. The TestCase
| boots an Orchestra Testbench Laravel app with the package's service
| provider registered, an in-memory SQLite database, and the package's
| migrations applied.
|
*/

use Devletes\NotificationsMax\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);
