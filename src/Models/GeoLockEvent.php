<?php

namespace Spiggle\GeoLock\Models;

use Illuminate\Database\Eloquent\Model;

class GeoLockEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'user_label',
        'roles',
        'ip',
        'country',
        'region',
        'source',
        'reason',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
