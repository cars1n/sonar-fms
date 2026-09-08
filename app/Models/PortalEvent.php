<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PortalEvent extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'delivered_at' => 'datetime', 'retry_at' => 'datetime'];
}
