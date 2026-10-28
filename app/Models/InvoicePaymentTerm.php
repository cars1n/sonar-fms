<?php

namespace App\Models;

use App\Traits\BelongsToInvoice;
use App\Traits\HasResource;
use App\Traits\UsesUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperInvoicePaymentTerm
 */
class InvoicePaymentTerm extends Model
{
    use BelongsToInvoice;
    use HasFactory;
    use HasResource;
    use UsesUuid;

    protected $fillable = [
        'uuid',
        'invoice_uuid',
        'invoice_payment_schedule_uuid',
        'batch_id',
        'amount',
        'amount_due',
        'remaining_balance',
        'due_at',
        'notified_at',
        'notify',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'notify_at' => 'datetime',
        'amount' => 'int',
        'notify' => 'boolean',
    ];

    // These are the attributes/properties that are
    // used on the invoice form based on the API Resource
    public static array $formAttributes = [
        'id',
        'amount',
        'percentage',
        'due_at',
    ];

    public function invoicePaymentSchedule(): BelongsTo
    {
        return $this->belongsTo(InvoicePaymentSchedule::class, 'invoice_payment_schedule_uuid', 'uuid');
    }
}
