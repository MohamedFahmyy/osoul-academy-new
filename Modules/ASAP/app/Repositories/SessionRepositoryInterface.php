<?php

namespace Modules\ASAP\Repositories;

use Modules\ASAP\Models\ExamSession;

interface SessionRepositoryInterface
{
    public function findById(string $id): ?ExamSession;

    public function create(array $data): ExamSession;

    public function updateStatus(string $id, string $status): bool;

    public function updateRiskScore(string $id, float $score): bool;
}
