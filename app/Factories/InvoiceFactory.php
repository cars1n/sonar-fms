<?php

namespace App\Factories;

use App\Jobs\CreateInvoicePdf;
use App\Jobs\SendNewInvoiceNotification;
use App\Jobs\SetStudentCachedValues;
use App\Models\Invoice;
use App\Models\InvoiceImport;
use App\Models\InvoiceItem;
use App\Models\InvoicePaymentSchedule;
use App\Models\InvoicePaymentTerm;
use App\Models\InvoiceScholarship;
use App\Models\School;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

abstract class InvoiceFactory extends BaseImportFactory
{
    public ?School $school = null;

    protected Collection $students;

    protected User $user;

    protected string $invoiceNumberPrefix = '';

    protected ?string $originalBatchId = null;

    protected ?string $pdfBatchId = null;

    protected Collection $studentInvoiceMap;

    protected Collection $invoiceNumberMap;

    // These are the collections that store the attributes
    // that need to be stored in the db
    protected Collection $invoices;

    protected Collection $invoiceItems;

    protected Collection $invoiceScholarships;

    protected Collection $itemScholarshipPivot;

    protected Collection $invoicePaymentSchedules;

    protected Collection $invoicePaymentTerms;

    protected Collection $invoiceTaxItems;

    protected array $fillableInvoiceAttributes;

    protected array $fillableInvoiceItemAttributes;

    protected array $fillableScholarshipAttributes;

    protected array $fillablePaymentScheduleAttributes;

    protected array $fillablePaymentTermAttributes;

    protected string $now;

    protected string $notifyAt;

    protected bool $asDraft = false;

    protected string $activityDescription;

    public function __construct()
    {
        ray()->newScreen('Invoice factory');

        $this->batchId = $this->uuid();
        $this->invoices = collect();
        $this->invoiceItems = collect();
        $this->invoiceScholarships = collect();
        $this->itemScholarshipPivot = collect();
        $this->invoicePaymentSchedules = collect();
        $this->invoicePaymentTerms = collect();
        $this->invoiceTaxItems = collect();
        $this->studentInvoiceMap = collect();
        $this->invoiceNumberMap = collect();
        $this->results = collect();

        $this->fillableInvoiceAttributes = (new Invoice)->getFillable();
        $this->fillableInvoiceItemAttributes = (new InvoiceItem)->getFillable();
        $this->fillableScholarshipAttributes = (new InvoiceScholarship)->getFillable();
        $this->fillablePaymentScheduleAttributes = (new InvoicePaymentSchedule)->getFillable();
        $this->fillablePaymentTermAttributes = (new InvoicePaymentTerm)->getFillable();

        $this->now = now()->toDateTimeString();
        $this->notifyAt = now()->addMinutes(15)->toIso8601String();
    }

    public function asDraft(bool $asDraft = true): static
    {
        $this->asDraft = $asDraft;

        return $this;
    }

    public function withOriginalBatchId(?string $batchId): static
    {
        $this->originalBatchId = $batchId;

        // Creating the student id => invoice id mapping to
        // preserve the original invoice uuids
        if ($this->originalBatchId) {
            $invoices = Invoice::batch($this->originalBatchId)
                ->select('uuid', 'student_uuid', 'invoice_number')
                ->get();
            $this->studentInvoiceMap = $invoices->pluck('uuid', 'student_uuid');
            $this->invoiceNumberMap = $invoices->pluck('invoice_number', 'student_uuid');
        }

        return $this;
    }

    public function noActivityLogging(): static
    {
        $this->logActivity = false;

        return $this;
    }

    public function withActivityDescription(string $description): static
    {
        $this->activityDescription = $description;

        return $this;
    }

    public function withUpdateActivityDescription(): static
    {
        // __(':user updated the draft invoice.')
        // __(':user updated and published the invoice.')
        $description = $this->asDraft
            ? ':user updated the draft invoice.'
            : ':user updated and published the invoice.';

        return $this->withActivityDescription($description);
    }

    protected function uuid(): string
    {
        return UuidFactory::make();
    }

    protected function cleanAttributes(array $attributes, array $fillable, array $additional = ['created_at', 'updated_at']): array
    {
        $allowed = array_merge($fillable, $additional);

        return Arr::only($attributes, $allowed);
    }

    protected function cleanInvoiceAttributes(array $attributes): array
    {
        return $this->cleanAttributes($attributes, $this->fillableInvoiceAttributes);
    }

    protected function cleanInvoiceItemAttributes(array $attributes): array
    {
        return $this->cleanAttributes($attributes, $this->fillableInvoiceItemAttributes);
    }

    protected function cleanInvoiceScholarshipAttributes(array $attributes): array
    {
        return $this->cleanAttributes($attributes, $this->fillableScholarshipAttributes);
    }

    protected function cleanPaymentScheduleAttributes(array $attributes): array
    {
        return $this->cleanAttributes($attributes, $this->fillablePaymentScheduleAttributes);
    }

    protected function cleanPaymentTermAttributes(array $attributes): array
    {
        return $this->cleanAttributes($attributes, $this->fillablePaymentTermAttributes);
    }

    protected function store(): Collection
    {
        ray(
            'Data to insert',
            $this->invoices->toArray(),
            $this->invoiceItems->toArray(),
            $this->invoiceScholarships->toArray(),
            $this->itemScholarshipPivot->toArray(),
            $this->invoicePaymentSchedules->toArray(),
            $this->invoicePaymentTerms->toArray(),
            $this->invoiceTaxItems->toArray(),
        )->green();

        DB::transaction(function () {
            // Delete the original batch and replace it with the new one
            if ($this->originalBatchId) {
                DB::table('invoice_batches')
                    ->where('uuid', $this->originalBatchId)
                    ->delete();
            }

            DB::table('invoice_batches')
                ->insert([
                    'uuid' => $this->batchId,
                    'created_at' => $this->now,
                ]);

            DB::table('invoices')
                ->insert($this->invoices->toArray());

            DB::table('invoice_items')
                ->insert($this->invoiceItems->toArray());

            DB::table('invoice_scholarships')
                ->insert($this->invoiceScholarships->toArray());

            DB::table('invoice_item_invoice_scholarship')
                ->insert($this->itemScholarshipPivot->toArray());

            DB::table('invoice_payment_schedules')
                ->insert($this->invoicePaymentSchedules->toArray());

            DB::table('invoice_payment_terms')
                ->insert($this->invoicePaymentTerms->toArray());

            DB::table('invoice_tax_items')
                ->insert($this->invoiceTaxItems->toArray());

            if ($this->logActivity) {
                // __('Created by :user.');
                // __('Created as a draft by :user.');
                $description = $this->asDraft
                    ? 'Created as a draft by :user.'
                    : 'Created by :user.';

                if (! empty($this->activityDescription)) {
                    $description = $this->activityDescription;
                }

                DB::table(config('activitylog.table_name'))
                    ->insert($this->invoices->map(fn (array $invoice) => [
                        'log_name' => 'default',
                        'description' => $description,
                        'subject_type' => 'invoice',
                        'subject_id' => $invoice['uuid'],
                        'causer_type' => 'user',
                        'causer_id' => $this->user->id,
                        'created_at' => $this->now,
                        'updated_at' => $this->now,
                        'batch_uuid' => $this->batchId,
                    ])->toArray());
            }
        });

        if (config('services.crm_portal.enabled')) {
            foreach (Invoice::whereIn('uuid', $this->invoices->pluck('uuid'))->get() as $invoice) {
                app(\App\Services\PortalPublisher::class)->capture($invoice);
            }
        }

        $uuids = $this->invoices->map(function (array $invoice) {
            if ($invoice['notify'] && ! $this->asDraft) {
                SendNewInvoiceNotification::dispatch($invoice['uuid'])
                    ->delay(Carbon::parse($invoice['notify_at']));
            }

            // Kick off caching account balances
            if (! $this->asDraft && ($invoice['student_uuid'] ?? false)) {
                SetStudentCachedValues::dispatch($invoice['student_uuid']);
            }

            return $invoice['uuid'];
        });

        // Start the batch for the pdfs after dispatching the account balance jobs
        $batch = Bus::batch(
            $this->invoices->pluck('uuid')
                ->map(fn ($uuid) => new CreateInvoicePdf($uuid))
        )
            ->then(function (Batch $batch) {
                InvoiceImport::where('pdf_batch_id', $batch->id)
                    ->update(['pdf_batch_id' => null]);
            })
            ->catch(function (Batch $batch, \Throwable $e) {
                Log::error($e->getMessage());
            })
            ->dispatch();

        $this->pdfBatchId = $batch->id;

        return $uuids;
    }
}
