<?php

namespace Modules\ASAP\Repositories;

use Modules\ASAP\Models\ExamSession;

class EloquentSessionRepository implements SessionRepositoryInterface
{
    public function findById(string $id): ?ExamSession
    {
        return ExamSession::find($id);
    }

    public function create(array $data): ExamSession
    {
        return ExamSession::create($data);
    }

    public function updateStatus(string $id, string $status): bool
    {
        $session = ExamSession::find($id);
        if (!$session) {
            return false;
        }
        return $session->update(['status' => $status]);
    }

    public function updateRiskScore(string $id, float $score): bool
    {
        $session = ExamSession::find($id);
        if (!$session) {
            return false;
        }
        return $session->update(['risk_score' => $score]);
    }
}
