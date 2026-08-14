<?php

namespace Modules\ASAP\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Telemetry extends Model
{
    protected $table = 'asap_telemetries';

    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'telemetry_schema_version',
        'payload',
        'recorded_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'recorded_at' => 'datetime',
    ];

    /**
     * Get the session this telemetry belongs to.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id', 'id');
    }
}
