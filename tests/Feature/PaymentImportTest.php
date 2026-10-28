<?php

namespace Tests\Feature;

use App\Factories\PaymentFromImportFactory;
use App\Jobs\ProcessPaymentImport;
use App\Models\Activity;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentImport;
use App\Models\PaymentMethod;
use App\Utilities\NumberUtility;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\CreatesInvoice;
use Tests\Traits\CreatesPaymentImports;
use Tests\Traits\GetsUploadedFiles;
use Tests\Traits\MapsFields;

class PaymentImportTest extends TestCase
{
    use CreatesInvoice;
    use CreatesPaymentImports;
    use GetsUploadedFiles;
    use MapsFields;
    use RefreshDatabase;
    use WithFaker;

    protected bool $signIn = true;

    public function test_cant_view_imports_page_without_permission()
    {
        $this->get(route('payments.imports.index'))
            ->assertForbidden();
    }

    public function test_can_view_imports_with_permission()
    {
        $this->assignPermission('view', PaymentImport::class);

        $this->get('/payments/imports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('title')
                ->has('imports')
                ->has('permissions')
                ->component('payments/imports/Index')
            );
    }

    public function test_can_view_the_create_form()
    {
        $this->assignPermission('create', PaymentImport::class);

        $this->get(route('payments.imports.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('title')
                ->has('breadcrumbs')
                ->has('extensions')
                ->where('method', 'post')
                ->where('endpoint', route('payments.imports.store'))
                ->component('invoices/imports/Create')
            );
    }

    public function test_can_create_payment_import()
    {
        $this->withoutExceptionHandling();
        $this->assignPermission('create', PaymentImport::class);
        Storage::fake();

        $data = [
            'files' => [[
                'file' => $this->getUploadedFile('small_payments.xlsx'),
            ]],
            'heading_row' => 1,
            'starting_row' => 2,
        ];

        $this->post(route('payments.imports.store'), $data)
            ->assertSessionHas('success')
            ->assertRedirect();

        $this->assertEquals(1, $this->school->paymentImports()->count());
        $this->assertEquals(1, $this->user->paymentImports()->count());

        /** @var PaymentImport $import */
        $import = $this->user->paymentImports()->first();
        Storage::assertExists($import->file_path);
        $this->assertEquals(1, $import->heading_row);
        $this->assertEquals(2, $import->starting_row);
        $this->assertEquals(4, $import->total_records);
        $this->assertEquals(0, $import->imported_records);
        $this->assertEquals(0, $import->failed_records);
    }

    public function test_can_view_edit_import_page()
    {
        $this->assignPermission('update', PaymentImport::class);
        $import = PaymentImport::create([
            'tenant_id' => $this->tenant->id,
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => '/tmp/file.csv',
        ]);

        $this->get(route('payments.imports.edit', $import))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('title')
                ->has('breadcrumbs')
                ->has('extensions')
                ->has('existingImport')
                ->where('method', 'put')
                ->where('endpoint', route('payments.imports.update', $import))
                ->component('invoices/imports/Create')
            );
    }

    public function test_can_view_update_existing_import_without_new_file()
    {
        $this->assignPermission('update', PaymentImport::class);
        Storage::fake();

        $import = $this->createImport('single_payment.csv');
        $data = [
            'files' => [[
                'file' => null,
                'existing' => true,
            ]],
            'heading_row' => 2,
            'starting_row' => 3,
        ];

        $this->put(route('payments.imports.update', $import), $data)
            ->assertSessionHas('success')
            ->assertRedirect();

        $import->refresh();
        $this->assertEquals('single_payment.csv', $import->file_name);
        $this->assertEquals(2, $import->heading_row);
        $this->assertEquals(3, $import->starting_row);
        Storage::assertExists($import->file_path);
    }

    public function test_can_view_update_existing_import_with_new_file()
    {
        $this->assignPermission('update', PaymentImport::class);
        Storage::fake();

        $import = $this->createImport('single_payment.csv');
        $originalPath = $import->file_path;
        $data = [
            'files' => [[
                'file' => $this->getUploadedFile('small_payments.xlsx'),
            ]],
            'heading_row' => 1,
            'starting_row' => 2,
        ];

        $this->put(route('payments.imports.update', $import), $data)
            ->assertSessionHas('success')
            ->assertRedirect();

        $import->refresh();
        $this->assertEquals('small_payments.xlsx', $import->file_name);
        $this->assertEquals(1, $import->heading_row);
        $this->assertEquals(2, $import->starting_row);
        $this->assertEquals(4, $import->total_records);
        Storage::assertExists($import->file_path);
        Storage::assertMissing($originalPath);
        Storage::assertMissing(dirname($originalPath));
    }

    public function test_can_get_to_mapping_page()
    {
        $this->assignPermission('update', PaymentImport::class);
        $import = $this->createImport();

        $this->get(route('payments.imports.map', $import))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('title')
                ->has('breadcrumbs')
                ->has('paymentImport')
                ->has('headers')
                ->component('payments/imports/Map')
            );
    }

    public function test_can_save_valid_mapping()
    {
        $this->assignPermission('update', PaymentImport::class);
        $import = $this->createImport();

        // Just do the bare minimum
        $data = [
            'invoice_column' => 'invoice number',
            'invoice_payment_term' => $this->makeMapField(),
            'payment_method' => $this->makeMapField(),
            'transaction_details' => $this->makeMapField(),
            'paid_at' => $this->makeMapField('date'),
            'amount' => $this->makeMapField('amount'),
            'made_by' => $this->makeMapField(),
            'notes' => $this->makeMapField(),
        ];

        $this->put(route('payments.imports.map', $import), $data)
            ->assertSessionHas('success')
            ->assertRedirect(route('payments.imports.show', $import));

        $import->refresh();
        $this->assertNotEmpty($import->mapping);
        $this->assertTrue($import->mapping_valid);
    }

    public function test_can_save_invalid_mapping()
    {
        $this->assignPermission('update', PaymentImport::class);
        $import = $this->createImport();

        // Just do the bare minimum
        $data = [
            'invoice_column' => null,
            'invoice_payment_term' => $this->makeMapField(),
            'payment_method' => $this->makeMapField(),
            'transaction_details' => $this->makeMapField(),
            'paid_at' => $this->makeMapField(),
            'amount' => $this->makeMapField(),
            'made_by' => $this->makeMapField(),
            'notes' => $this->makeMapField(),
        ];

        $this->put(route('payments.imports.map', $import), $data)
            ->assertSessionHas('success')
            ->assertRedirect(route('payments.imports.show', $import));

        $import->refresh();
        $this->assertNotEmpty($import->mapping);
        $this->assertFalse($import->mapping_valid);

        $errors = $import->getMappingValidationErrors();
        $this->assertCount(3, $errors);
        $this->assertArrayHasKey('invoice_column', $errors);
        $this->assertArrayHasKey('paid_at', $errors);
        $this->assertArrayHasKey('amount', $errors);
    }

    public function test_can_view_import_show_page()
    {
        $this->assignPermission('view', PaymentImport::class);
        $import = $this->createImport();

        $this->get(route('payments.imports.show', $import))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('title')
                ->has('breadcrumbs')
                ->where('paymentImport', json_decode($import->refresh()->load('user')->toResource()->toJson(), true))
                ->has('permissions')
                ->where('previewResults', [])
                ->has('results')
                ->component('payments/imports/Show')
            );
    }

    public function test_can_redirect_preview()
    {
        $this->assignPermission('create', InvoicePayment::class);
        $import = $this->createImport();

        $this->get(route('payments.imports.preview', $import))
            ->assertRedirect(route('payments.imports.show', $import).'?preview=1');
    }

    public function test_can_import_payments()
    {
        $this->assignPermission('create', InvoicePayment::class);
        $import = $this->createImport(attributes: [
            'heading_row' => 1,
            'starting_row' => 2,
        ]);
        $cash = PaymentMethod::factory()->create(['driver' => 'cash']);
        $bank = PaymentMethod::factory()->create(['driver' => 'bank_transfer']);
        Bus::fake();

        $import->mapping = [
            'invoice_column' => 'invoice number',
            'invoice_payment_term' => $this->makeMapField(),
            'payment_method' => $this->makeMapField('payment method'),
            'transaction_details' => $this->makeMapField('transaction details'),
            'paid_at' => $this->makeMapField('date'),
            'amount' => $this->makeMapField('amount'),
            'made_by' => $this->makeMapField('paid by'),
            'notes' => $this->makeMapField('notes'),
        ];
        $import->mapping_valid = $import->hasValidMapping();
        $import->save();

        $this->addPaymentInvoices($import, 'invoice number');

        $this->post(route('payments.imports.start', $import))
            ->assertSessionHas('success')
            ->assertRedirect();

        Bus::assertDispatched(ProcessPaymentImport::class);

        // Since we're faking the bus, we need to run the job manually
        (new ProcessPaymentImport($import, $this->user))
            ->handle();

        $import->refresh();
        $this->assertEquals(4, $this->school->invoicePayments()->count());
        $this->assertNotNull($import->job_batch_id);
        $this->assertNotNull($import->import_batch_id);
        $invoices = $this->school->invoices()
            ->with('invoicePayments')
            ->get()
            ->keyBy('invoice_number');

        Bus::assertBatched(function (PendingBatch $batch) use ($import) {
            return $batch->name === "Payment import {$import->id}" &&
                $batch->jobs->count() === 4;
        });

        $this->assertTrue(Activity::where('batch_uuid', $import->import_batch_id)->exists());

        foreach ($import->getImportContents() as $row) {
            /** @var Invoice $invoice */
            $invoice = $invoices->get(strtoupper($row->get('invoice number')));

            $this->assertEquals(1, $invoice->invoicePayments->count());
            /** @var InvoicePayment $payment */
            $payment = $invoice->invoicePayments->first();

            $amount = NumberUtility::sanitizeNumber($row->get('amount'));
            $this->assertEquals($amount * 100, $payment->amount);
            $this->assertEquals($import->id, $payment->payment_import_id);

            // All the dates for this import are the same
            $this->assertEquals('2021-12-01', $payment->paid_at->toDateString());
            $this->assertEquals($row->get('notes'), $payment->notes);
            $this->assertEquals($row->get('transaction details'), $payment->transaction_details);

            if ($method = $row->get('payment method')) {
                $id = str_contains($method, 'cash')
                    ? $cash->id
                    : $bank->id;

                $this->assertEquals($id, $payment->payment_method_id);
            } else {
                $this->assertNull($payment->payment_method_id);
            }
        }
    }

    public function test_can_import_payments_with_combined_invoices()
    {
        $this->assignPermission('create', InvoicePayment::class);
        $import = $this->createImport('medium_payments.xlsx', [
            'heading_row' => 1,
            'starting_row' => 2,
        ]);
        $cash = PaymentMethod::factory()->create(['driver' => 'cash']);
        $bank = PaymentMethod::factory()->create(['driver' => 'bank_transfer']);
        Bus::fake();
        Queue::fake();

        $import->update([
            'mapping' => [
                'invoice_column' => 'invoice number',
                'invoice_payment_term' => $this->makeMapField(),
                'payment_method' => $this->makeMapField('payment method'),
                'transaction_details' => $this->makeMapField('transaction details'),
                'paid_at' => $this->makeMapField('date'),
                'amount' => $this->makeMapField('amount'),
                'made_by' => $this->makeMapField('paid by'),
                'notes' => $this->makeMapField('notes'),
            ],
        ]);

        $this->addPaymentInvoices($import, 'invoice number', true);
        $import->unsetRelations();

        ray()->measure();
        (new ProcessPaymentImport($import, $this->user))
            ->handle();
        ray()->measure();

        $import->refresh();
        $this->assertEquals($import->getImportContents()->count(), $this->school->invoicePayments()->whereNull('parent_uuid')->count());
        $this->assertNotNull($import->job_batch_id);
        $invoices = $this->school->invoices()
            ->with('invoicePayments', 'children.invoicePayments')
            ->get()
            ->keyBy('invoice_number');

        // This import shouldn't have any failed rows, assume all gets batched
        Bus::assertBatched(function (PendingBatch $batch) use ($import) {
            return $batch->name === "Payment import {$import->id}";
        });

        foreach ($import->getImportContents() as $row) {
            /** @var Invoice $invoice */
            $invoice = $invoices->get(strtoupper($row->get('invoice number')));

            $this->assertEquals(1, $invoice->invoicePayments->count());
            /** @var InvoicePayment $payment */
            $payment = $invoice->invoicePayments->first();

            $amount = NumberUtility::sanitizeNumber($row->get('amount'));
            $this->assertEquals($amount * 100, $payment->amount);

            // All the dates for this import are the same
            $this->assertEquals('2021-12-15', $payment->paid_at->toDateString());
            $this->assertEquals($row->get('notes'), $payment->notes);
            $this->assertEquals($row->get('transaction details'), $payment->transaction_details);

            if ($method = $row->get('payment method')) {
                $id = str_contains($method, 'cash')
                    ? $cash->id
                    : $bank->id;

                $this->assertEquals($id, $payment->payment_method_id);
            } else {
                $this->assertNull($payment->payment_method_id);
            }

            if ($invoice->children->isNotEmpty()) {
                foreach ($invoice->children as $child) {
                    $this->assertEquals(1, $child->invoicePayments->count());
                    /** @var InvoicePayment $childPayment */
                    $childPayment = $child->invoicePayments->first();
                    $this->assertEquals($payment->uuid, $childPayment->parent_uuid);
                    $this->assertNotEquals($amount, $childPayment->amount);
                }
            }
        }
    }

    public function test_can_import_payments_with_made_by()
    {
        $this->assignPermission('create', InvoicePayment::class);
        $import = $this->createImport('made_by_payments.xlsx', [
            'heading_row' => 1,
            'starting_row' => 2,
        ]);
        Bus::fake();

        $import->update([
            'mapping' => [
                'invoice_column' => 'invoice number',
                'invoice_payment_term' => $this->makeMapField(),
                'payment_method' => $this->makeMapField(),
                'transaction_details' => $this->makeMapField(),
                'paid_at' => $this->makeMapField('date'),
                'amount' => $this->makeMapField('amount'),
                'made_by' => $this->makeMapField('paid by'),
                'notes' => $this->makeMapField(),
            ],
        ]);

        $this->addPaymentInvoices($import, 'invoice number');
        $madeByEmail = 'real@example.com';
        $createdUser = $this->createUser(['email' => $madeByEmail]);
        $rowCount = $import->getImportContents()->count();

        (new ProcessPaymentImport($import, $this->user))
            ->handle();

        $import->refresh();
        $this->assertEquals($rowCount, $this->school->invoicePayments()->count());
        $this->assertNotNull($import->job_batch_id);
        $invoices = $this->school->invoices()
            ->with('invoicePayments')
            ->get()
            ->keyBy('invoice_number');

        Bus::assertBatched(function (PendingBatch $batch) use ($import, $rowCount) {
            return $batch->name === "Payment import {$import->id}" &&
                $batch->jobs->count() === $rowCount;
        });

        foreach ($import->getImportContents() as $row) {
            /** @var Invoice $invoice */
            $invoice = $invoices->get(strtoupper($row->get('invoice number')));

            $this->assertEquals(1, $invoice->invoicePayments->count());
            /** @var InvoicePayment $payment */
            $payment = $invoice->invoicePayments->first();

            $amount = NumberUtility::sanitizeNumber($row->get('amount'));
            $this->assertEquals($amount * 100, $payment->amount);

            // All the dates for this import are the same
            $this->assertEquals('2021-12-01', $payment->paid_at->toDateString());
            $this->assertNull($payment->notes);
            $this->assertNull($payment->transaction_details);

            if ($row->get('paid by') === $madeByEmail) {
                $this->assertEquals($createdUser->uuid, $payment->made_by);
            } else {
                $this->assertNull($payment->made_by);
            }
        }

        $this->assertTrue(
            collect($import->results)->some(function ($result) {
                return count($result['warnings']) > 0;
            })
        );
    }

    public function test_can_get_import_payments_as_models()
    {
        $this->assignPermission('create', InvoicePayment::class);
        $import = $this->createImport(attributes: [
            'heading_row' => 1,
            'starting_row' => 2,
        ]);
        Bus::fake();

        $import->update([
            'mapping' => [
                'invoice_column' => 'invoice number',
                'invoice_payment_term' => $this->makeMapField(),
                'payment_method' => $this->makeMapField('payment method'),
                'transaction_details' => $this->makeMapField('transaction details'),
                'paid_at' => $this->makeMapField('date'),
                'amount' => $this->makeMapField('amount'),
                'made_by' => $this->makeMapField('paid by'),
                'notes' => $this->makeMapField('notes'),
            ],
        ]);

        $this->addPaymentInvoices($import, 'invoice number');

        ray()->measure();
        $results = PaymentFromImportFactory::make($import, $this->user)
            ->asModels()
            ->build();
        ray()->measure();

        $this->assertEquals(0, $this->school->invoicePayments()->count());
        $this->assertNull($import->job_batch_id);
        Bus::assertNothingDispatched();

        $this->assertEquals($import->getImportContents()->count(), $results->get('models')->count());
        $this->assertTrue(
            collect($results->get('paymentImport')->results)->every(fn ($result) => $result['successful'])
        );
        $contents = $import->getImportContents();
        /** @var PaymentImport $modelImport */
        $modelImport = $results->get('paymentImport');

        $this->assertEquals($contents->count(), $modelImport->imported_records);
        $this->assertEquals(0, $modelImport->failed_records);

        /**
         * @var int $index
         * @var InvoicePayment $model
         */
        foreach ($results->get('models') as $index => $model) {
            $row = $contents->get($index);
            $amount = NumberUtility::sanitizeNumber($row->get('amount')) * 100;

            $this->assertEquals($amount, $model->amount);
            $this->assertEquals($model->invoice->amount_due - $amount, $model->invoice->remaining_balance);
        }
    }

    public function test_can_download_payment_import_file()
    {
        $this->assignPermission('view', PaymentImport::class);
        Storage::fake();
        $import = $this->createImport(attributes: [
            'heading_row' => 1,
            'starting_row' => 2,
        ]);

        $this->get(route('payments.imports.download', $import))
            ->assertDownload($import->file_name);
    }

    public function test_can_roll_back_import()
    {
        $this->withoutExceptionHandling();
        $this->assignPermission('create', InvoicePayment::class);
        $this->assignPermission('roll back', PaymentImport::class);
        $import = $this->createImport(attributes: [
            'heading_row' => 1,
            'starting_row' => 2,
        ]);
        Bus::fake();

        $import->mapping = [
            'invoice_column' => 'invoice number',
            'invoice_payment_term' => $this->makeMapField(),
            'payment_method' => $this->makeMapField(),
            'transaction_details' => $this->makeMapField('transaction details'),
            'paid_at' => $this->makeMapField('date'),
            'amount' => $this->makeMapField('amount'),
            'made_by' => $this->makeMapField('paid by'),
            'notes' => $this->makeMapField('notes'),
        ];
        $import->mapping_valid = $import->hasValidMapping();
        $import->save();

        $this->addPaymentInvoices($import, 'invoice number');

        // Since we're faking the bus, we need to run the job manually
        (new ProcessPaymentImport($import, $this->user))
            ->handle();

        $import->refresh();
        $this->assertEquals(4, $this->school->invoicePayments()->count());
        $this->assertNotNull($import->job_batch_id);

        Bus::assertBatched(function (PendingBatch $batch) use ($import) {
            return $batch->name === "Payment import {$import->id}" &&
                $batch->jobs->count() === 4;
        });

        $this->post(route('payments.imports.rollback', $import))
            ->assertRedirect()
            ->assertSessionHas('success');

        $import->refresh();
        $this->assertEquals(0, $import->imported_records);
        $this->assertNotNull($import->rolled_back_at);
        $this->assertNull($import->imported_at);
        $this->assertEquals(0, $this->school->invoicePayments()->count());

        Bus::assertBatched(function (PendingBatch $batch) use ($import) {
            return $batch->name === "Roll back {$import->id}" &&
                $batch->jobs->count() === 4;
        });
    }

    /**
     * This test does not fake the queue, so the SetInvoiceRemainingBalance
     * job gets fired for both the invoice and parent invoice
     */
    public function test_can_import_payment_to_child_invoice()
    {
        $this->assignPermission('create', InvoicePayment::class);
        $import = $this->createImport('single_payment.csv', attributes: [
            'heading_row' => 1,
            'starting_row' => 2,
        ]);
        $import->mapping = [
            'invoice_column' => 'invoice number',
            'invoice_payment_term' => $this->makeMapField(),
            'payment_method' => $this->makeMapField(),
            'transaction_details' => $this->makeMapField('transaction details'),
            'paid_at' => $this->makeMapField('date'),
            'amount' => $this->makeMapField('amount'),
            'made_by' => $this->makeMapField(),
            'notes' => $this->makeMapField(),
        ];
        $import->save();

        $parent = $this->createCombinedInvoice();
        /** @var Invoice $invoice */
        $invoice = $parent->children->random();
        $row = $import->getImportContents()->first();

        // Update the invoice number to match the file's
        $invoice->update(['invoice_number' => $row->get('invoice number')]);

        (new ProcessPaymentImport($import, $this->user))
            ->handle();

        $import->refresh();
        $parent->refresh();
        $invoice->refresh();
        $amount = NumberUtility::sanitizeNumber($row->get('amount')) * 100;

        $this->assertTrue(
            $invoice->activities->some(fn ($activity) => str_contains($activity->description, 'imported a payment'))
        );
        $this->assertTrue(
            $parent->activities->some(fn ($activity) => str_contains($activity->description, 'imported a payment'))
        );

        $this->assertEquals($invoice->amount_due - $amount, $invoice->remaining_balance);
        $this->assertEquals($parent->amount_due - $amount, $parent->remaining_balance);
        $this->assertEquals(1, $invoice->invoicePayments()->count());
        $this->assertEquals(0, $parent->invoicePayments()->count());
    }
}
