<?php

namespace App\Models;

/**
 * @property string|null $config_name
 * @property string $config_value
 */
class EndingConfig extends EndingRecord
{
    protected $table = 'ending_config';

    protected $guarded = ['*'];
}
