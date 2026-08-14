<?php

namespace Modules\ASAP\Services\Policy;

use Modules\ASAP\Models\ExamSession;

class DecisionEngine
{
    /**
     * Map a risk score to the corresponding policy action.
     */
    public function resolveAction(ExamSession $session, float $riskScore): string
    {
        $policy = $session->policy;
        if (!$policy) {
            return 'allow';
        }

        if ($riskScore >= $policy->terminate_threshold) {
            return 'terminate';
        }

        if ($riskScore >= $policy->pause_threshold) {
            return 'pause';
        }

        if ($riskScore >= $policy->warning_threshold) {
            return 'warn';
        }

        return 'allow';
    }
}
