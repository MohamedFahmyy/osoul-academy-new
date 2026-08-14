<?php

namespace Modules\ASAP\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityEvent extends Model
{
    protected $table = 'asap_security_events';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'session_id',
        'event_code',
        'payload',
        'severity',
        'source',
        'category',
        'client_sequence',
        'correlation_id',
        'processed_at',
        'policy_action',
        'risk_delta',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'client_sequence' => 'integer',
        'risk_delta' => 'float',
        'processed_at' => 'datetime',
        'occurred_at' => 'datetime',
    ];

    /**
     * Get the session this event belongs to.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id', 'id');
    }
}
