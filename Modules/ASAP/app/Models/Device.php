<?php

namespace Modules\ASAP\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ASAP\Enums\DeviceStatus;

class Device extends Model
{
    protected $table = 'asap_devices';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'uuid',
        'name',
        'operating_system',
        'status',
        'hardware_hash',
        'hardware_version',
        'registration_method',
        'last_seen_at',
    ];

    protected $casts = [
        'status' => DeviceStatus::class,
        'last_seen_at' => 'datetime',
    ];

    /**
     * Get exam sessions on this device.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(ExamSession::class, 'device_id', 'id');
    }
}
