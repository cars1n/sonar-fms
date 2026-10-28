<?php

namespace App\Models;

use App\Traits\BelongsToInvoice;
use App\Traits\BelongsToSchool;
use App\Traits\BelongsToTenant;
use App\Traits\BelongsToUser;
use App\Traits\HasAmountAttribute;
use App\Traits\HasResource;
use GrantHolle\Timezone\Facades\Timezone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperInvoiceRefund
 */
class InvoiceRefund extends Model
{
    use BelongsToInvoice;
    use BelongsToSchool;
    use BelongsToTenant;
    use BelongsToUser;
    use HasAmountAttribute;
    use HasFactory;
    use HasResource;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'refunded_at' => 'datetime',
    ];

    public function getRefundedAtFormattedAttribute(): string
    {
        if (! $this->refunded_at) {
            return '';
        }

        return Timezone::toLocal($this->refunded_at, 'M j, Y');
    }
}
