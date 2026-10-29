<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property CarbonImmutable|null $created_at
 * @property string|null $legacy_checksum
 * @property array<string, string>|null $legacy_dates
 */
abstract class EndingRecord extends Model
{
    public $timestamps = false;

    protected $hidden = ['legacy_checksum', 'legacy_dates'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'legacy_dates' => 'array'];
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:sP');
    }
}
