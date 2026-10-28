<?php

namespace App\Models;

use App\Concerns\FileImport;
use App\Factories\InvoiceFromImportFactory;
use App\Jobs\PruneInvoicePdfs;
use App\Rules\FileImportMap;
use App\Rules\InvoiceImportAmountOrPercentage;
use App\Traits\BelongsToSchool;
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
use Illuminate\Validation\Rule;

/**
 * @mixin IdeHelperInvoiceImport
 */
class InvoiceImport extends Model implements FileImport
{
    use BelongsToSchool;
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
        $builder->when($filters['s'] ?? null, function (Builder $builder, string $search) {});
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function invoicePdfs(): HasManyThrough
    {
        return $this->hasManyThrough(
            InvoicePdf::class,
            Invoice::class,
            'invoice_import_id',
            'invoice_uuid',
            'id',
            'uuid',
        );
    }

    public function getMappingValidator(): \Illuminate\Validation\Validator
    {
        $mapping = $this->mapping ?? [];

        return Validator::make($mapping, [
            'student_attribute' => ['required', Rule::in(['sis_id', 'student_number', 'email'])],
            'student_column' => 'required',
            'title' => new FileImportMap('required', true),
            'description' => new FileImportMap('nullable'),
            'import_date' => new FileImportMap('nullable|date'),
            'due_at' => new FileImportMap('nullable|date'),
            'available_at' => new FileImportMap('nullable|date'),
            'term_id' => new FileImportMap([
                'nullable',
                Rule::in($this->school->terms->pluck('id')),
            ]),
            'grade_level_adjustment' => new FileImportMap(['nullable', 'integer']),
            'notify' => 'boolean',
            'items' => 'required|array|min:1',
            'items.*.fee_id' => new FileImportMap([
                'nullable',
                Rule::in($this->school->fees->pluck('id')),
            ]),
            'items.*.name' => new FileImportMap('required', true),
            'items.*.amount_per_unit' => new FileImportMap('required|integer', true),
            'items.*.quantity' => new FileImportMap('required|integer', true),
            'scholarships' => 'array',
            'scholarships.*.scholarship_id' => new FileImportMap([
                'nullable',
                Rule::in($this->school->scholarships->pluck('id')),
            ]),
            'scholarships.*.name' => new FileImportMap('required', true),
            'scholarships.*.use_amount' => 'required|boolean',
            // required_without:scholarships.*.percentage
            'scholarships.*.amount' => [
                new FileImportMap('nullable|integer'),
                new InvoiceImportAmountOrPercentage($mapping, 'percentage'),
            ],
            // required_without:scholarships.*.amount
            'scholarships.*.percentage' => [
                new FileImportMap('nullable|numeric|max:100'),
                new InvoiceImportAmountOrPercentage($mapping, 'amount'),
            ],
            // I don't think this is needed here, we can set it manually
            'scholarships.*.resolution_strategy' => new FileImportMap([
                'nullable',
                'required_with:scholarships.*.amount,scholarships.*.percentage',
                Rule::in(array_keys(Scholarship::getResolutionStrategies())),
            ]),
            'scholarships.*.applies_to' => 'array',
            'payment_schedules' => 'array',
            'payment_schedules.*.terms' => 'array',
            'payment_schedules.*.terms.*.use_amount' => 'required|boolean',
            // required_without:payment_schedules.*.terms.*.percentage
            'payment_schedules.*.terms.*.amount' => [
                new FileImportMap('nullable|integer'),
                new InvoiceImportAmountOrPercentage($mapping, 'percentage'),
            ],
            // required_without:payment_schedules.*.terms.*.amount
            'payment_schedules.*.terms.*.percentage' => [
                new FileImportMap('nullable|numeric|max:100'),
                new InvoiceImportAmountOrPercentage($mapping, 'amount'),
            ],
            'payment_schedules.*.terms.*.due_at' => new FileImportMap('nullable|date'),
            'apply_tax' => [
                Rule::requiredIf(fn () => $this->school->collect_tax),
                'boolean',
            ],
            'use_school_tax_defaults' => [
                Rule::requiredIf(fn () => $this->school->collect_tax && ($mapping['apply_tax'] ?? false)),
                'boolean',
            ],
            'tax_rate' => new FileImportMap([
                Rule::requiredIf(fn () => $this->school->collect_tax &&
                    ($mapping['apply_tax'] ?? false) &&
                    ! ($mapping['use_school_tax_defaults'] ?? false)
                ),
                'nullable',
                'numeric',
            ]),
            'tax_label' => new FileImportMap([
                Rule::requiredIf(fn () => $this->school->collect_tax &&
                    ($mapping['apply_tax'] ?? false) &&
                    ! ($mapping['use_school_tax_defaults'] ?? false)
                ),
                'nullable',
                'min:1',
            ]),
            'apply_tax_to_all_items' => [
                Rule::requiredIf(fn () => $this->school->collect_tax &&
                    ($mapping['apply_tax'] ?? false)
                ),
                'boolean',
            ],
            'tax_items' => [
                Rule::requiredIf(fn () => $this->school->collect_tax &&
                    ($mapping['apply_tax'] ?? false) &&
                    ! ($mapping['apply_tax_to_all_items'] ?? false)
                ),
                'array',
            ],
            'tax_items.*.item_id' => 'required|in_array:items.*.id',
            'tax_items.*.selected' => 'required|boolean',
            'tax_items.*.tax_rate' => [
                new FileImportMap('required|numeric', true),
            ],
        ]);
    }

    public function rollBack(): static
    {
        // If the batch is still processing, cancel it
        if ($this->pdf_batch_id) {
            Bus::findBatch($this->pdf_batch_id)
                ?->cancel();
        }

        // Dispatch jobs to delete any invoice files
        Bus::batch(
            $this->invoicePdfs()
                ->pluck('relative_path')
                ->map(fn ($path) => new PruneInvoicePdfs($path))
        )
            ->catch(function (Batch $batch, \Throwable $e) {
                // Do something
            })
            ->name("Invoice import {$this->id}")
            ->dispatch();

        $this->invoices()->delete();

        return $this->reset();
    }

    public function importAsModels(): Collection
    {
        return InvoiceFromImportFactory::make($this)
            ->asModels()
            ->build();
    }
}
