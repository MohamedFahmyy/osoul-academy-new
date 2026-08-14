<?php

namespace Modules\ASAP\Repositories;

use Modules\ASAP\Models\Device;

class EloquentDeviceRepository implements DeviceRepositoryInterface
{
    public function findById(string $id): ?Device
    {
        return Device::find($id);
    }

    public function findByUuid(string $uuid): ?Device
    {
        return Device::where('uuid', $uuid)->first();
    }

    public function create(array $data): Device
    {
        return Device::create($data);
    }

    public function update(string $id, array $data): bool
    {
        $device = Device::find($id);
        if (!$device) {
            return false;
        }
        return $device->update($data);
    }
}
