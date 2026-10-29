<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $fillable = ['name', 'mobile_number', 'email', 'password', 'status'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    /** @return HasOne<AdminProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(AdminProfile::class);
    }

    /** @return BelongsToMany<AdminPermission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(AdminPermission::class, 'admin_user_permissions', 'user_id', 'permission_id');
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'mobile_number' => $this->mobile_number,
            'email' => $this->email, 'status' => $this->status,
            'permissions' => $this->permissions()->orderBy('module_order')->get(['admin_permissions.id', 'name', 'module']),
        ];
    }
}
