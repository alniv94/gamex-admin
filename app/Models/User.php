<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;

    protected string $guard_name = 'web';

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

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'mobile_number' => $this->mobile_number,
            'email' => $this->email, 'status' => $this->status,
            'permissions' => $this->status === 'Active' && $this->profile
                ? $this->getAllPermissions()->pluck('name')->sort()->values()->all()
                : [],
            'roles' => $this->getRoleNames()->sort()->values()->all(),
        ];
    }
}
