<?php

namespace Modules\ASAP\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceStateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'operating_system' => $this->operating_system,
            'status' => $this->status?->value ?? $this->status,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
