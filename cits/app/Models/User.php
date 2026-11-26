<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'email_notify' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')->withTimestamps();
    }

    public function isClient(): bool
    {
        return $this->user_type === 'client';
    }

    /** May see and use the internal side (internal accounts, and system admins of any type). */
    public function seesInternal(): bool
    {
        return $this->is_admin || ! $this->isClient();
    }

    /** May use client-only actions such as opening a ticket (client accounts, and system admins). */
    public function actsAsClient(): bool
    {
        return $this->is_admin || $this->isClient();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
