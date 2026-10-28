<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasResource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperDepartment
 */
class Department extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasResource;

    protected $guarded = [];
}
