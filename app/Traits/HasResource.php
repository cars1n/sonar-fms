<?php

namespace App\Traits;

use GrantHolle\Http\Resources\Traits\HasResource as DetectsResource;
use Illuminate\Http\Resources\Json\JsonResource;

trait HasResource
{
    use DetectsResource;

    public function toResource(?string $resourceClass = null): JsonResource
    {
        return $resourceClass === null
            ? static::resource($this)
            : $resourceClass::make($this);
    }
}
