<?php

namespace App\Models;

use App\Concerns\FileImport;
use App\Factories\PaymentFromImportFactory;
use App\Jobs\SetInvoiceRemainingBalance;
use App\Rules\FileImportMap;
use App\Traits\BelongsToSchool;
use App\Traits\BelongsToTenant;
use App\Traits\BelongsToUser;
use App\Traits\HasResource;
use App\Traits\ImportsFiles;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Validator;

/**
 * @mixin IdeHelperPaymentImport
 */
class PaymentImport extends Model implements FileImport
{
    use BelongsToSchool;
    use BelongsToTenant;
    use BelongsToUser;
    use HasResource;
    use ImportsFiles;

    protected $guarded = [];

    protected $casts = [
        'mapping' => 'json',
        'results' => 'json',
        'total_records' => 'int',
        'imported_records' => 'int',
        'failed_records' => 'int',
        'heading_row' => 'int',
        'starting_row' => 'int',
        'imported_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function scopeFilter(Builder $builder, array $filters)
    {
        $builder->when($filters['s'] ?? null, function (Builder $builder, string $search) {
            $builder->where('file_path', 'ilike', "/%{$search}%");
        });
    }

    public function invoicePayments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function invoices(): HasManyThrough
    {
        return $this->hasManyThrough(
            Invoice::class,
            InvoicePayment::class,
            'payment_import_id',
            'uuid',
            'id',
            'invoice_uuid',
        )->distinct();
    }

    public function getMappingValidator(): \Illuminate\Validation\Validator
    {
        $mapping = $this->mapping ?? [];

        return Validator::make($mapping, [
            'invoice_column' => 'required',
            'invoice_payment_term' => new FileImportMap('nullable'),
            'payment_method' => new FileImportMap('nullable'),
            'transaction_details' => new FileImportMap('nullable'),
            'paid_at' => new FileImportMap('required|date', true),
            'amount' => new FileImportMap('required', true),
            'made_by' => new FileImportMap('nullable'),
            'notes' => new FileImportMap('nullable'),
        ]);
    }

    public function rollBack(): static
    {
        $invoices = $this->invoices()->pluck('invoices.uuid');

        $this->invoicePayments()->delete();

        // Delete the activity logs for both invoices
        // and payments
        Activity::query()->where('batch_uuid', $this->import_batch_id)
            ->delete();

        // Run jobs on the invoices to recalculate balances
        Bus::batch(
            $invoices->map(fn ($uuid) => new SetInvoiceRemainingBalance($uuid))
        )->catch(function (Batch $batch, \Throwable $e) {
            //
        })
            ->name("Roll back {$this->id}")
            ->dispatch();

        return $this->reset();
    }

    public function reset(): static
    {
        $this->update([
            'rolled_back_at' => now(),
            'imported_at' => null,
            'failed_records' => 0,
            'imported_records' => 0,
            'results' => null,
            'import_batch_id' => null,
        ]);

        return $this;
    }

    public function importAsModels(User $user): Collection
    {
        return PaymentFromImportFactory::make($this, $user)
            ->asModels()
            ->build();
    }
}
