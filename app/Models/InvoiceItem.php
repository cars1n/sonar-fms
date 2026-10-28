<?php

namespace App\Models;

use App\Traits\BelongsToInvoice;
use App\Traits\HasResource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperInvoiceItem
 */
class InvoiceItem extends Model
{
    use BelongsToInvoice;
    use HasFactory;
    use HasResource;

    protected $fillable = [
        'uuid',
        'invoice_uuid',
        'batch_id',
        'fee_id',
        'name',
        'description',
        'amount_per_unit',
        'amount',
        'quantity',
    ];

    // These are the attributes/properties that are
    // used on the invoice form based on the API Resource
    public static array $formAttributes = [
        'id',
        'fee_id',
        'name',
        'amount_per_unit',
        'quantity',
    ];

    public function getIncrementing(): bool
    {
        return false;
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(Fee::class);
    }

    public function getAmountFormattedAttribute(): ?string
    {
        if (
            ! $this->relationLoaded('invoice') ||
            ! $this->invoice->relationLoaded('currency')
        ) {
            return null;
        }

        return displayCurrency($this->amount, $this->invoice->currency);
    }

    public function getAmountPerUnitFormattedAttribute()
    {
        if (
            ! $this->relationLoaded('invoice') ||
            ! $this->invoice->relationLoaded('currency')
        ) {
            return null;
        }

        return displayCurrency($this->amount_per_unit, $this->invoice->currency);
    }

    public function setAmount(): static
    {
        $this->amount = $this->calculateTotal();

        return $this;
    }

    public function calculateTotal(): int
    {
        return $this->amount_per_unit * $this->quantity;
    }
}
