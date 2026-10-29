<?php

namespace App\Models;

/**
 * @property string|null $name
 */
class Player extends EndingRecord
{
    protected $guarded = ['*'];

    protected $visible = ['id', 'name'];
}
