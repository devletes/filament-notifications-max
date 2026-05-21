<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Tests\Stubs;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

/**
 * User stub that opts into Filament's per-panel access contract.
 *
 * The auth-providers default User stub allows all panels (no contract
 * implemented = Filament's default-allow). This subclass implements
 * {@see FilamentUser} and reads an `allowedPanels` array off the model
 * so individual tests can constrain access for the URL resolver to
 * exercise.
 */
class RestrictedUser extends User implements FilamentUser
{
    protected $table = 'users';

    /** @var array<int, string>|null */
    public ?array $allowedPanels = null;

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->allowedPanels === null) {
            return true;
        }

        return in_array($panel->getId(), $this->allowedPanels, true);
    }
}
