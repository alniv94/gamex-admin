<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminProfile extends Model
{
    protected $table = 'admin_profiles';

    protected $guarded = [];

    protected $hidden = ['legacy_payload', 'source_checksum'];

    protected function casts(): array
    {
        return ['legacy_payload' => 'encrypted:array', 'birthdate' => 'date', 'session_version' => 'integer'];
    }
}
