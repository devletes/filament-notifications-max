<?php

namespace Workbench\App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Workbench\Database\Factories\UserFactory;

/**
 * Workbench user model — opts into Filament's per-panel access contract
 * so the multi-panel flow can be exercised cleanly: each seeded user
 * carries an `allowed_panels` array, and clicking around the workbench
 * shows the preferences page filtering itself per panel access.
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'allowed_panels',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'allowed_panels' => 'array',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        $allowed = $this->allowed_panels ?? [];

        return in_array($panel->getId(), $allowed, true);
    }
}
