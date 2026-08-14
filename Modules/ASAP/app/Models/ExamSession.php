<?php

namespace Modules\ASAP\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ASAP\Enums\SessionStatus;
use Modules\Exam\Models\Exam;

class ExamSession extends Model
{
    protected $table = 'asap_sessions';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'device_id',
        'user_id',
        'exam_id',
        'policy_id',
        'status',
        'session_key_id',
        'session_key_hash',
        'session_key_encrypted',
        'bootstrap_token',
        'bootstrap_token_expires_at',
        'expires_at',
        'rotated_at',
        'risk_score',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'status' => SessionStatus::class,
        'risk_score' => 'float',
        'bootstrap_token_expires_at' => 'datetime',
        'expires_at' => 'datetime',
        'rotated_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    /**
     * Get the device associated with the session.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'id');
    }

    /**
     * Get the user taking the exam.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Get the exam being taken.
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    /**
     * Get the policy applied to this session.
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'policy_id', 'id');
    }

    /**
     * Get the telemetries recorded for this session.
     */
    public function telemetries(): HasMany
    {
        return $this->hasMany(Telemetry::class, 'session_id', 'id');
    }

    /**
     * Get the security events for this session.
     */
    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class, 'session_id', 'id');
    }

    /**
     * Get the incidents recorded for this session.
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'session_id', 'id');
    }
}
