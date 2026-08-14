<?php

namespace Modules\ASAP\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evidence extends Model
{
    protected $table = 'asap_evidences';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'incident_id',
        'telemetry_snapshot',
        'event_snapshot',
        'ip_address',
        'client_version',
        'policy_version',
        'risk_engine_version',
        'sdk_version',
        'os_version',
        'decision',
        'decision_source',
        'engine_build',
        'correlation_snapshot',
        'decision_reason',
    ];

    protected $casts = [
        'telemetry_snapshot' => 'array',
        'event_snapshot' => 'array',
        'correlation_snapshot' => 'array',
    ];

    /**
     * Get the incident this evidence belongs to.
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id', 'id');
    }
}
