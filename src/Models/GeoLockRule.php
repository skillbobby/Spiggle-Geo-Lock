<?php

namespace Spiggle\GeoLock\Models;

use Illuminate\Database\Eloquent\Model;

class GeoLockRule extends Model
{
    protected $fillable = [
        'roles',
        'type',
        'countries',
        'regions',
        'is_active',
        'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'countries' => 'array',
            'regions' => 'array',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }
}
