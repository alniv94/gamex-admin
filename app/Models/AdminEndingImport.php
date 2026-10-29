<?php

namespace App\Models;

class AdminEndingImport extends EndingRecord
{
    protected $table = 'admin_ending_imports';

    protected $guarded = ['*'];

    protected $hidden = ['report'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['report' => 'array', 'verified_at' => 'immutable_datetime'];
    }
}
