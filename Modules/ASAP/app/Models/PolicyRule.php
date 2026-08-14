<?php

namespace Modules\ASAP\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ASAP\Enums\PolicyAction;

class PolicyRule extends Model
{
    protected $table = 'asap_policy_rules';

    protected $fillable = [
        'policy_id',
        'event_code',
        'weight',
        'cooldown_window',
        'action',
    ];

    protected $casts = [
        'weight' => 'float',
        'cooldown_window' => 'integer',
        'action' => PolicyAction::class,
    ];

    /**
     * Get the policy this rule belongs to.
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'policy_id', 'id');
    }
}
