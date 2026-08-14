<?php

namespace Modules\ASAP\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\ASAP\Enums\IncidentStatus;

class Incident extends Model
{
    protected $table = 'asap_incidents';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'session_id',
        'status',
        'risk_score_snapshot',
    ];

    protected $casts = [
        'status' => IncidentStatus::class,
        'risk_score_snapshot' => 'float',
    ];

    /**
     * Get the session this incident occurred in.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id', 'id');
    }

    /**
     * Get the evidence package associated with this incident.
     */
    public function evidence(): HasOne
    {
        return $this->hasOne(Evidence::class, 'incident_id', 'id');
    }
}
