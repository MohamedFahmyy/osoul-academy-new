<?php

namespace Modules\ASAP\Repositories;

use Modules\ASAP\Models\Device;

interface DeviceRepositoryInterface
{
    public function findById(string $id): ?Device;

    public function findByUuid(string $uuid): ?Device;

    public function create(array $data): Device;

    public function update(string $id, array $data): bool;
}
