<?php

namespace Modules\ASAP\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Policy extends Model
{
    protected $table = 'asap_policies';

    protected $fillable = [
        'name',
        'warning_threshold',
        'pause_threshold',
        'terminate_threshold',
        'is_default',
    ];

    protected $casts = [
        'warning_threshold' => 'float',
        'pause_threshold' => 'float',
        'terminate_threshold' => 'float',
        'is_default' => 'boolean',
    ];

    /**
     * Get the rules associated with this policy.
     */
    public function rules(): HasMany
    {
        return $this->hasMany(PolicyRule::class, 'policy_id', 'id');
    }
}
