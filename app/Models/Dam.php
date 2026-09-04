<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dam extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'utc_offset_minutes' => 'integer',
        'crest_elevation' => 'float',
        'normal_water_level' => 'float',
        'flood_water_level' => 'float',
        'minimum_water_level' => 'float',
        'gross_storage_mcm' => 'float',
        'meta' => 'array',
    ];

    public function stations(): HasMany
    {
        return $this->hasMany(SensorStation::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function maintenanceTasks(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
