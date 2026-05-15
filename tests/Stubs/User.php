<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Tests\Stubs;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Test user model. Has the columns the package's defaults probe:
 * `tenant_id` (multi-tenant scope), `email` (mail channel), `phone`
 * (twilio / vonage channel routing).
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property ?int $tenant_id
 * @property ?string $phone
 */
class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = true;

    /**
     * Mail-channel route — typically the user's email column. Returns the
     * stored email; tests that need a different shape override on the
     * instance via the model's attributes.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }
}
