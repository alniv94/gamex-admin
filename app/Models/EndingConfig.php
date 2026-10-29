<?php

namespace App\Models;

/**
 * @property string|null $config_name
 * @property string $config_value
 */
class EndingConfig extends EndingRecord
{
    public const array EDITABLE_NAMES = [
        'Game Betting Disabled', 'Card Allow Open', 'Hours allowed to create additional card',
        'Traditional Card - 1st Quarter Prize Percent', 'Traditional Card - 2nd Quarter Prize Percent',
        'Traditional Card - 3rd Quarter Prize Percent', 'Traditional Card - 4th Quarter Prize Percent',
        'Per Quarter Card - 1st Quarter Prize Percent', 'Per Quarter Card - 2nd Quarter Prize Percent',
        'Per Quarter Card - 3rd Quarter Prize Percent', 'Per Quarter Card - 4th Quarter Prize Percent',
    ];

    protected $table = 'ending_config';

    protected $guarded = ['*'];
}
