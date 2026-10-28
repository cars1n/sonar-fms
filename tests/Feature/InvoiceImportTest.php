<?php

namespace Tests\Feature;

use App\Events\InvoiceImportFinished;
use App\Http\Resources\InvoiceResource;
use App\Jobs\ProcessInvoiceImport;
use App\Models\Activity;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\InvoiceImport;
use App\Models\InvoiceItem;
use App\Models\InvoiceLayout;
use App\Models\InvoicePaymentSchedule;
use App\Models\InvoicePaymentTerm;
use App\Models\InvoicePdf;
use App\Models\InvoiceScholarship;
use App\Models\InvoiceTaxItem;
use App\Models\InvoiceTemplate;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\PendingBatchFake;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\GetsUploadedFiles;
use Tests\Traits\MapsFields;
use Tests\Traits\SignsIn;

class InvoiceImportTest extends TestCase
{
    use GetsUploadedFiles;
    use MapsFields;
    use RefreshDatabase;
    use SignsIn;

    private const FIXTURE_DUE_DATES_BY_STUDENT_NUMBER = [
        '1' => '2021-10-01',
        '2' => '2021-10-02',
        '3' => '2021-10-03',
    ];

    public function test_cannot_access_imports_without_permission()
    {
        $this->get(route('invoices.imports.index'))
            ->assertForbidden();
    }

    public function test_can_access_import_with_permission()
    {
        $this->assignPermission('view', InvoiceImport::class);

        $this->get(route('invoices.imports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('title')
                ->has('imports')
            );
    }

    public function test_can_access_create_page()
    {
        $this->assignPermission('create', InvoiceImport::class);

        $this->get(route('invoices.imports.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('title')
                ->has('extensions')
                ->has('breadcrumbs')
                ->where('endpoint', route('invoices.imports.store'))
                ->where('method', 'post')
                ->component('invoices/imports/Create')
            );
    }

    public function test_can_create_invoice_import()
    {
        $this->withoutExceptionHandling();
        $this->assignPermission('create', InvoiceImport::class);
        Storage::fake();

        $data = [
            'files' => [[
                'file' => $this->getUploadedFile('sonar-import.xls'),
            ]],
            'heading_row' => 1,
            'starting_row' => 2,
        ];

        $this->post(route('invoices.imports.store'), $data)
            ->assertSessionHas('success')
            ->assertRedirect();

        $this->assertEquals(1, $this->school->invoiceImports()->count());
        $this->assertEquals(1, $this->user->invoiceImports()->count());

        /** @var InvoiceImport $import */
        $import = $this->user->invoiceImports()->first();
        Storage::assertExists($import->file_path);
        $this->assertEquals(1, $import->heading_row);
        $this->assertEquals(2, $import->starting_row);
        $this->assertEquals(4, $import->total_records);
        $this->assertEquals(0, $import->imported_records);
        $this->assertEquals(0, $import->failed_records);
    }

    public function test_can_view_edit_import_page()
    {
        $this->assignPermission('update', InvoiceImport::class);
        $import = InvoiceImport::create([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => '/tmp/file.csv',
        ]);

        $this->get(route('invoices.imports.edit', $import))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('title')
                ->has('breadcrumbs')
                ->has('extensions')
                ->has('existingImport')
                ->where('method', 'put')
                ->where('endpoint', route('invoices.imports.update', $import))
                ->component('invoices/imports/Create')
            );
    }

    public function test_can_view_download_import_file()
    {
        $this->assignPermission('view', InvoiceImport::class);
        $path = (new InvoiceImport)->storeFile($this->getUploadedFile('sonar-import.xls'), $this->school);
        $import = InvoiceImport::create([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => $path,
        ]);

        $this->get(route('invoices.imports.download', $import))
            ->assertDownload($import->file_name);
    }

    public function test_can_save_mapping_as_template()
    {
        $this->assignPermission('create', InvoiceImport::class);
        $import = InvoiceImport::create([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => '/tmp/file.csv',
            'mapping' => [
                'key1' => 'value',
                'key2' => 'value',
                'key3' => 'value',
            ],
        ]);

        $this->post(route('invoices.imports.template', $import), ['name' => 'import template'])
            ->assertSessionHas('success')
            ->assertRedirect();

        $this->assertDatabaseHas('invoice_templates', [
            'user_uuid' => $this->user->uuid,
            'school_id' => $this->school->id,
            'for_import' => true,
            'name' => 'import template',
        ]);
        $template = InvoiceTemplate::first();
        $this->assertEquals($import->mapping, $template->template);
    }

    public function test_can_update_existing_import_with_different_file()
    {
        $this->assignPermission('update', InvoiceImport::class);
        Storage::fake();

        $originalPath = (new InvoiceImport)->storeFile($this->getUploadedFile('sonar-import.xls'), $this->school);
        $import = InvoiceImport::create([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => $originalPath,
        ]);

        $data = [
            'files' => [[
                'file' => $this->getUploadedFile(),
            ]],
            'heading_row' => 2,
            'starting_row' => 3,
        ];

        $this->put(route('invoices.imports.update', $import), $data)
            ->assertRedirect()
            ->assertSessionHas('success');

        $import->refresh();
        $this->assertEquals('sonar-import.xlsx', $import->file_name);
        $this->assertEquals(2, $import->heading_row);
        $this->assertEquals(3, $import->starting_row);
        $this->assertEquals(3, $import->total_records);
        Storage::assertExists($import->file_path);
        Storage::assertMissing($originalPath);
        Storage::assertMissing(dirname($originalPath));
    }

    public function test_can_update_existing_import_with_same_file()
    {
        $this->withoutExceptionHandling();
        $this->assignPermission('update', InvoiceImport::class);
        Storage::fake();

        $originalPath = (new InvoiceImport)->storeFile(UploadedFile::fake()->create('original.xlsx', 2), $this->school);
        $import = InvoiceImport::create([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => $originalPath,
        ]);

        $data = [
            'files' => [[
                'file' => null,
                'existing' => true,
            ]],
            'heading_row' => 2,
            'starting_row' => 3,
        ];

        $this->put(route('invoices.imports.update', $import), $data)
            ->assertRedirect()
            ->assertSessionHas('success');

        $import->refresh();
        $this->assertEquals('original.xlsx', $import->file_name);
        $this->assertEquals(2, $import->heading_row);
        $this->assertEquals(3, $import->starting_row);
        Storage::assertExists($import->file_path);
    }

    public function test_can_save_import_mapping_passing_validation()
    {
        $this->withoutExceptionHandling();
        $this->assignPermission('update', InvoiceImport::class);
        Storage::fake();
        $this->school->update([
            'collect_tax' => true,
            'tax_rate' => 0.05,
            'tax_label' => 'Taxes',
        ]);

        $originalPath = (new InvoiceImport)->storeFile(
            $this->getUploadedFile(),
            $this->school
        );
        $import = InvoiceImport::create([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => $originalPath,
            'heading_row' => 2,
            'starting_row' => 3,
        ]);

        $itemId = $this->uuid();
        $data = [
            'student_attribute' => 'student_number',
            'student_column' => 'student number',
            'title' => $this->makeMapField(value: 'Invoice title', isManual: true),
            'description' => $this->makeMapField(),
            'due_at' => $this->makeMapField('due date'),
            'available_at' => $this->makeMapField('available date'),
            'term_id' => $this->makeMapField(),
            'notify' => false,
            'items' => [
                [
                    'id' => $itemId,
                    'fee_id' => $this->makeMapField(),
                    'name' => $this->makeMapField('invoice name'),
                    'amount_per_unit' => $this->makeMapField('invoice amount'),
                    'quantity' => $this->makeMapField(value: 1, isManual: true),
                ],
            ],
            'scholarships' => [
                [
                    'id' => $this->uuid(),
                    'name' => $this->makeMapField(value: 'Assistance', isManual: true),
                    'use_amount' => false,
                    'amount' => $this->makeMapField(),
                    'percentage' => $this->makeMapField('discount'),
                    'applies_to' => [],
                ],
            ],
            'payment_schedules' => [],
            'apply_tax' => true,
            'use_school_tax_defaults' => false,
            'tax_rate' => $this->makeMapField(value: 0.07, isManual: true),
            'tax_label' => $this->makeMapField(value: 'VAT', isManual: true),
            'apply_tax_to_all_items' => false,
            'tax_items' => [
                [
                    'item_id' => $itemId,
                    'selected' => true,
                    'tax_rate' => $this->makeMapField(value: 10, isManual: true),
                ],
            ],
        ];

        $this->putJson(route('invoices.imports.map', $import), $data)
            ->assertSessionHas('success')
            ->assertRedirect();

        $import->refresh();
        $this->assertEquals($data, $import->mapping);
        $this->assertTrue($import->mapping_valid);
    }

    public function test_can_queue_import()
    {
        $this->withoutExceptionHandling();
        $this->assignPermission('create', InvoiceImport::class);
        Storage::fake();
        Event::fake();

        $originalPath = (new InvoiceImport)->storeFile(
            $this->getUploadedFile('sonar-import.xls'),
            $this->school
        );
        $import = InvoiceImport::create([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => $originalPath,
            'heading_row' => 1,
            'starting_row' => 2,
            'mapping' => [
                'student_attribute' => 'student_number',
                'student_column' => 'student number',
                'title' => $this->makeMapField(value: 'Invoice title', isManual: true),
                'description' => $this->makeMapField(),
                'due_at' => $this->makeMapField('due date'),
                'available_at' => $this->makeMapField('available date'),
                'term_id' => $this->makeMapField(),
                'notify' => false,
                'items' => [
                    [
                        'id' => $this->uuid(),
                        'fee_id' => $this->makeMapField(),
                        'name' => $this->makeMapField('invoice name'),
                        'amount_per_unit' => $this->makeMapField('invoice amount'),
                        'quantity' => $this->makeMapField(value: 1, isManual: true),
                    ],
                ],
                'scholarships' => [
                    [
                        'id' => $this->uuid(),
                        'name' => $this->makeMapField(value: 'Assistance', isManual: true),
                        'use_amount' => false,
                        'amount' => $this->makeMapField(),
                        'percentage' => $this->makeMapField('discount'),
                        'applies_to' => [],
                    ],
                ],
                'payment_schedules' => [],
            ],
        ]);

        // Check that the import validity is checked
        $this->post(route('invoices.imports.start', $import))
            ->assertSessionHas('error')
            ->assertRedirect();

        // Check for valid mapping
        $import->mapping_valid = $import->hasValidMapping();
        $this->assertTrue($import->mapping_valid);
        $import->save();

        // Check that the import validity is checked
        $this->post(route('invoices.imports.start', $import))
            ->assertSessionHas('success')
            ->assertRedirect();

        Event::assertDispatched(InvoiceImportFinished::class);
    }

    public function test_can_import_simple_xls()
    {
        $this->withoutExceptionHandling();
        Storage::fake();
        Event::fake();
        Bus::fake();
        Queue::fake();

        $originalPath = (new InvoiceImport)->storeFile(
            $this->getUploadedFile('sonar-import.xls'),
            $this->school
        );
        /** @var Term $term */
        $term = $this->school->terms()
            ->save(
                Term::factory()->make()
            );
        $import = InvoiceImport::make([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => $originalPath,
            'heading_row' => 1,
            'starting_row' => 2,
            'mapping' => [
                'student_attribute' => 'student_number',
                'student_column' => 'student number',
                'title' => $this->makeMapField(value: 'Invoice title', isManual: true),
                'description' => $this->makeMapField(),
                'invoice_date' => $this->makeMapField('invoice date'),
                'due_at' => $this->makeMapField('due date'),
                'available_at' => $this->makeMapField('available date'),
                'term_id' => $this->makeMapField(null, $term->id, true),
                'notify' => false,
                'items' => [
                    [
                        'id' => $this->uuid(),
                        'fee_id' => $this->makeMapField(),
                        'name' => $this->makeMapField('invoice name'),
                        'amount_per_unit' => $this->makeMapField('invoice amount'),
                        'quantity' => $this->makeMapField(value: 1, isManual: true),
                    ],
                ],
                'scholarships' => [
                    [
                        'id' => $this->uuid(),
                        'name' => $this->makeMapField(value: 'Assistance', isManual: true),
                        'use_amount' => false,
                        'amount' => $this->makeMapField(),
                        'percentage' => $this->makeMapField('discount'),
                        'applies_to' => [],
                    ],
                ],
                'payment_schedules' => [],
            ],
        ]);
        $import->mapping_valid = $import->hasValidMapping();
        $import->setTotalRecords();
        $import->save();

        // Change the students to match the student numbers
        $students = $this->school->students->random($import->total_records);

        $import->getImportContents()
            ->each(function (Collection $row, $index) use ($students) {
                $studentNumber = $row->get('student number');

                if (! blank($studentNumber)) {
                    $students->get($index)->update(['student_number' => $row->get('student number')]);
                }
            });

        $job = new ProcessInvoiceImport($import);
        $job->handle();

        $import->refresh();
        $contents = $import->getImportContents();
        $contentsByStudentNumber = $contents->keyBy('student number');

        $values = $contents
            ->pluck('student number')
            ->filter(fn ($value) => ! is_null($value));

        $students = $import->school->students()
            ->whereIn('student_number', $values)
            ->orderByDesc('student_number')
            ->get();

        $students->each(function (Student $student) use ($contentsByStudentNumber, $term) {
            $this->assertEquals(1, $student->invoices->count());
            /** @var Invoice $invoice */
            $invoice = $student->invoices->first();
            $this->assertEquals(1, $invoice->invoiceItems->count());
            $row = $contentsByStudentNumber->get($student->student_number);
            $total = $row['invoice amount'] * 100;
            $discount = 0;

            if (! empty($row['discount'])) {
                $this->assertEquals(1, $invoice->invoiceScholarships->count());
                /** @var InvoiceScholarship $scholarship */
                $scholarship = $invoice->invoiceScholarships->first();
                $discount = $scholarship->calculated_amount;
                $this->assertEquals(0, $scholarship->amount);
                $this->assertEquals($row['discount'], $scholarship->percentage);
                $this->assertEquals($total * $row['discount'], $scholarship->calculated_amount);
            }

            /** @var InvoiceItem $item */
            $item = $invoice->invoiceItems->first();
            $this->assertEquals($total, $item->amount);
            $this->assertEquals($total, $item->amount_per_unit);

            $due = $total - $discount;
            if ($due < 0) {
                $due = 0;
            }
            $this->assertNotNull($invoice->invoice_number);
            $this->assertEquals($term->id, $invoice->term_id);
            $this->assertEquals($due, $invoice->amount_due);
            $this->assertEquals($due, $invoice->remaining_balance);
            $this->assertEquals($total, $invoice->subtotal);
            $this->assertEquals($discount, $invoice->discount_total);
            $this->assertNotNull($invoice->invoiceImport);
            $this->assertTrue(
                Carbon::parse(self::FIXTURE_DUE_DATES_BY_STUDENT_NUMBER[$student->student_number], $this->user->timezone)
                    ->equalTo($invoice->due_at)
            );
            $this->assertTrue(
                Carbon::create(2021, 9, 1, 8, 0, 0, $this->user->timezone)
                    ->equalTo($invoice->available_at)
            );
            $this->assertEquals(
                '2021-09-01',
                $invoice->invoice_date->format('Y-m-d')
            );
        });

        Bus::assertBatched(function (PendingBatchFake $batch) {
            return $batch->jobs->count() === 3;
        });
        $this->assertNotNull($import->pdf_batch_id);

        $this->assertEquals(3, $import->imported_records);
        $this->assertEquals(1, $import->failed_records);
        $this->assertCount(4, $import->results);
        $this->assertEquals(3, Activity::count());
        Event::assertDispatched(InvoiceImportFinished::class);
    }

    public function test_can_import_small_csv()
    {
        $this->withoutExceptionHandling();
        Storage::fake();
        Event::fake();
        Bus::fake();

        $originalPath = (new InvoiceImport)->storeFile(
            $this->getUploadedFile('small.csv'),
            $this->school
        );

        // Don't set the term explicitly, but it does qualify
        // as the "current term" when compiling the title
        /** @var Term $term */
        $term = $this->school->terms()
            ->save(
                Term::factory()->make()
            );

        $import = InvoiceImport::make([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => $originalPath,
            'heading_row' => 1,
            'starting_row' => 2,
            'mapping' => [
                'student_attribute' => 'student_number',
                'student_column' => 'student number',
                'title' => $this->makeMapField('name'),
                'description' => $this->makeMapField(),
                'invoice_date' => $this->makeMapField(),
                'due_at' => $this->makeMapField('due date'),
                'available_at' => $this->makeMapField('available date'),
                'term_id' => $this->makeMapField(),
                'notify' => false,
                'items' => [
                    [
                        'id' => $this->uuid(),
                        'fee_id' => $this->makeMapField(),
                        'name' => $this->makeMapField(value: 'Item one', isManual: true),
                        'amount_per_unit' => $this->makeMapField('amount'),
                        'quantity' => $this->makeMapField('quantity'),
                    ],
                ],
                'scholarships' => [],
                'payment_schedules' => [],
            ],
        ]);
        $import->mapping_valid = $import->hasValidMapping();
        $import->setTotalRecords();
        $import->save();

        // Change the students to match the student numbers
        $students = $this->school->students->random($import->total_records);

        $import->getImportContents()
            ->each(function (Collection $row, $index) use ($students) {
                $studentNumber = $row->get('student number');

                if (! blank($studentNumber)) {
                    $students->get($index)->update(['student_number' => $row->get('student number')]);
                }
            });

        $job = new ProcessInvoiceImport($import);
        $job->handle();

        $import->refresh();
        $contents = $import->getImportContents();

        $values = $contents
            ->pluck('student number')
            ->filter(fn ($value) => ! is_null($value));

        $students = $import->school->students()
            ->whereIn('student_number', $values)
            ->get();

        $students->each(function (Student $student) use ($term) {
            $this->assertEquals(1, $student->invoices->count());
            /** @var Invoice $invoice */
            $invoice = $student->invoices->first();
            $this->assertEquals(1, $invoice->invoiceItems->count());

            $this->assertEquals("{$student->last_name}, {$student->first_name} {$term->abbreviation} Invoice", $invoice->title);
            $this->assertEquals('{last_name}, {first_name} {term} Invoice', $invoice->raw_title);
            $this->assertNotNull($invoice->invoice_number);
            $this->assertNull($invoice->term_id);
            $this->assertNotNull($invoice->invoiceImport);
        });

        Event::assertDispatched(InvoiceImportFinished::class);
    }

    public function test_invoice_attribute_validation()
    {
        Storage::fake();
        Event::fake();
        Bus::fake();

        $originalPath = (new InvoiceImport)->storeFile(
            $this->getUploadedFile('small_invalid.csv'),
            $this->school
        );

        /** @var InvoiceImport $import */
        $import = InvoiceImport::make([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => $originalPath,
            'heading_row' => 1,
            'starting_row' => 2,
            'mapping' => [
                'student_attribute' => 'student_number',
                'student_column' => 'student number',
                'title' => $this->makeMapField('name'),
                'description' => $this->makeMapField(),
                'invoice_date' => $this->makeMapField(),
                'due_at' => $this->makeMapField('due date'),
                'available_at' => $this->makeMapField('available date'),
                'term_id' => $this->makeMapField(),
                'notify' => false,
                'items' => [
                    [
                        'id' => $this->uuid(),
                        'fee_id' => $this->makeMapField(),
                        'name' => $this->makeMapField(value: 'Item one', isManual: true),
                        'amount_per_unit' => $this->makeMapField('amount'),
                        'quantity' => $this->makeMapField('quantity'),
                    ],
                ],
                'scholarships' => [],
                'payment_schedules' => [],
            ],
        ]);
        $import->mapping_valid = $import->hasValidMapping();
        $import->setTotalRecords();
        $import->save();

        // Change the students to match the student numbers
        $students = $this->school->students->random($import->total_records);

        $import->getImportContents()
            ->each(function (Collection $row, $index) use ($students) {
                $studentNumber = $row->get('student number');

                if (! blank($studentNumber)) {
                    $students->get($index)->update(['student_number' => $row->get('student number')]);
                }
            });

        $job = new ProcessInvoiceImport($import);
        $job->handle();

        $import->refresh();
        $this->assertEquals($import->total_records, $import->failed_records);

        foreach ($import->results as $result) {
            $this->assertFalse($result['successful']);
            $this->assertStringContainsString('Title', $result['result']);
        }

        Event::assertDispatched(InvoiceImportFinished::class);
    }

    public function test_can_get_simple_xls_as_models()
    {
        $this->withoutExceptionHandling();
        Storage::fake();

        $originalPath = (new InvoiceImport)->storeFile(
            $this->getUploadedFile('sonar-import.xls'),
            $this->school
        );
        /** @var Term $term */
        $term = $this->school->terms()
            ->save(
                Term::factory()->make()
            );
        $import = InvoiceImport::make([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => $originalPath,
            'heading_row' => 1,
            'starting_row' => 2,
            'mapping' => [
                'student_attribute' => 'student_number',
                'student_column' => 'student number',
                'title' => $this->makeMapField(value: 'Invoice title', isManual: true),
                'description' => $this->makeMapField(),
                'due_at' => $this->makeMapField('due date'),
                'available_at' => $this->makeMapField('available date'),
                'term_id' => $this->makeMapField(null, $term->id, true),
                'notify' => false,
                'items' => [
                    [
                        'id' => $this->uuid(),
                        'fee_id' => $this->makeMapField(),
                        'name' => $this->makeMapField('invoice name'),
                        'amount_per_unit' => $this->makeMapField('invoice amount'),
                        'quantity' => $this->makeMapField(value: 1, isManual: true),
                    ],
                ],
                'scholarships' => [
                    [
                        'id' => $this->uuid(),
                        'name' => $this->makeMapField(value: 'Assistance', isManual: true),
                        'use_amount' => false,
                        'amount' => $this->makeMapField(),
                        'percentage' => $this->makeMapField('discount'),
                        'applies_to' => [],
                    ],
                ],
                'payment_schedules' => [],
            ],
        ]);
        $import->mapping_valid = $import->hasValidMapping();
        $import->setTotalRecords();
        $import->save();

        // Change the students to match the student numbers
        $students = $this->school->students->random($import->total_records);

        $import->getImportContents()
            ->each(function (Collection $row, $index) use ($students) {
                $studentNumber = $row->get('student number');

                if (! blank($studentNumber)) {
                    $students->get($index)->update(['student_number' => $row->get('student number')]);
                }
            });

        $models = $import->importAsModels();
        $resources = InvoiceResource::collection($models->get('models'))
            ->response()
            ->getData(true);

        ray('API Resources', $resources);
        $this->assertCount(3, $resources);
    }

    public function test_can_import_then_rollback_imported_records()
    {
        $this->assignPermission('roll back', InvoiceImport::class);
        $this->withoutExceptionHandling();
        Storage::fake();
        Storage::fake(config('filesystems.invoices'));
        Event::fake();
        Bus::fake();

        $originalPath = (new InvoiceImport)->storeFile(
            $this->getUploadedFile('sonar-import.xls'),
            $this->school
        );
        $import = InvoiceImport::make([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => $originalPath,
            'heading_row' => 1,
            'starting_row' => 2,
            'mapping' => [
                'student_attribute' => 'student_number',
                'student_column' => 'student number',
                'title' => $this->makeMapField(value: 'Invoice title', isManual: true),
                'description' => $this->makeMapField(),
                'due_at' => $this->makeMapField('due date'),
                'available_at' => $this->makeMapField('available date'),
                'term_id' => $this->makeMapField(),
                'notify' => false,
                'items' => [
                    [
                        'id' => $this->uuid(),
                        'fee_id' => $this->makeMapField(),
                        'name' => $this->makeMapField('invoice name'),
                        'amount_per_unit' => $this->makeMapField('invoice amount'),
                        'quantity' => $this->makeMapField(value: 1, isManual: true),
                    ],
                ],
                'scholarships' => [
                    [
                        'id' => $this->uuid(),
                        'name' => $this->makeMapField(value: 'Assistance', isManual: true),
                        'use_amount' => false,
                        'amount' => $this->makeMapField(),
                        'percentage' => $this->makeMapField('discount'),
                        'applies_to' => [],
                    ],
                ],
                'payment_schedules' => [],
            ],
        ]);
        $import->mapping_valid = $import->hasValidMapping();
        $import->setTotalRecords();
        $import->save();

        // Change the students to match the student numbers
        $students = $this->school->students->random($import->total_records);

        $import->getImportContents()
            ->each(function (Collection $row, $index) use ($students) {
                $studentNumber = $row->get('student number');

                if (! blank($studentNumber)) {
                    $students->get($index)->update(['student_number' => $row->get('student number')]);
                }
            });

        $job = new ProcessInvoiceImport($import);
        $job->handle();

        $import->refresh();
        $contents = $import->getImportContents();
        $values = $contents
            ->pluck('student number')
            ->filter(fn ($value) => ! is_null($value));
        $import->school->students()
            ->whereIn('student_number', $values)
            ->get();

        $this->assertEquals(3, $import->imported_records);
        $this->assertEquals(1, $import->failed_records);
        $this->assertCount(4, $import->results);

        // Seed some invoice pdfs
        /** @var InvoiceLayout $layout */
        $layout = InvoiceLayout::factory()->create();
        $import->invoices->each(function (Invoice $invoice) use ($layout) {
            $invoice->fakeSavePdf($layout);
        });

        $pdfs = $import->invoicePdfs()->get();
        $this->assertEquals($import->invoices->count(), $pdfs->count());
        $pdfs->each(function (InvoicePdf $pdf) {
            Invoice::getPdfDisk()->assertExists($pdf->relative_path);
        });

        $this->post(route('invoices.imports.rollback', $import))
            ->assertSessionHas('success')
            ->assertRedirect(route('invoices.imports.show', $import));

        $import->refresh();
        $this->assertEquals(0, $import->invoices()->count());
        $this->assertEquals(0, $import->imported_records);
        $this->assertEquals(0, $import->failed_records);
        $this->assertEmpty($import->results);

        $this->assertEquals(0, InvoiceItem::whereNotNull('invoice_uuid')->count());
        $this->assertEquals(0, InvoiceScholarship::whereNotNull('invoice_uuid')->count());

        Bus::assertBatched(function (PendingBatchFake $batch) use ($pdfs) {
            return $batch->jobs->count() === $pdfs->count();
        });
    }

    public function test_can_import_simple_csv_and_get_no_successful_results()
    {
        Storage::fake();
        Event::fake();
        Bus::fake();

        $originalPath = (new InvoiceImport)->storeFile(
            $this->getUploadedFile('fail.csv'),
            $this->school
        );
        $import = InvoiceImport::make([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => $originalPath,
            'heading_row' => 1,
            'starting_row' => 2,
            'mapping' => [
                'student_attribute' => 'student_number',
                'student_column' => 'student number',
                'title' => $this->makeMapField('name'),
                'description' => $this->makeMapField(),
                'due_at' => $this->makeMapField('due date'),
                'available_at' => $this->makeMapField('available date'),
                'term_id' => $this->makeMapField('term'),
                'notify' => false,
                'items' => [
                    [
                        'id' => $this->uuid(),
                        'fee_id' => $this->makeMapField(),
                        'name' => $this->makeMapField(value: 'Line item', isManual: true),
                        'amount_per_unit' => $this->makeMapField('amount'),
                        'quantity' => $this->makeMapField('quantity'),
                    ],
                ],
                'scholarships' => [],
                'payment_schedules' => [],
            ],
        ]);
        $import->mapping_valid = $import->hasValidMapping();
        $import->setTotalRecords();
        $import->save();

        // Change the students to match the student numbers
        $this->school->students->random(3)
            ->each(function (Student $student, int $index) {
                $student->update(['student_number' => $index + 1]);
            });

        ray()->measure();
        $job = new ProcessInvoiceImport($import);
        $job->handle();
        ray()->measure();

        $import->refresh();

        ray($import->invoices()->get());
        $this->assertEquals(0, $import->invoices()->count());
        $this->assertEquals(0, $import->imported_records);
        $this->assertEquals(3, $import->failed_records);
        $this->assertCount(3, $import->results);
        $this->assertTrue(collect($import->results)->every(fn ($r) => ! $r['successful']));
        Event::assertDispatched(InvoiceImportFinished::class);
    }

    public function test_can_import_huge_csv()
    {
        Storage::fake();
        Event::fake();
        Bus::fake();

        $originalPath = (new InvoiceImport)->storeFile(
            $this->getUploadedFile('huge.csv'),
            $this->school
        );
        $import = InvoiceImport::make([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => $originalPath,
            'heading_row' => 1,
            'starting_row' => 2,
            'mapping' => [
                'student_attribute' => 'student_number',
                'student_column' => 'student number',
                'title' => $this->makeMapField('name'),
                'description' => $this->makeMapField(),
                'due_at' => $this->makeMapField('due date'),
                'available_at' => $this->makeMapField('available date'),
                'term_id' => $this->makeMapField('term'),
                'notify' => false,
                'items' => [
                    [
                        'id' => $this->uuid(),
                        'fee_id' => $this->makeMapField(),
                        'name' => $this->makeMapField(value: 'Line item', isManual: true),
                        'amount_per_unit' => $this->makeMapField('amount'),
                        'quantity' => $this->makeMapField('quantity'),
                    ],
                ],
                'scholarships' => [
                    [
                        'id' => $this->uuid(),
                        'scholarship_id' => $this->makeMapField(),
                        'name' => $this->makeMapField(value: 'Discount', isManual: true),
                        'use_amount' => false,
                        'amount' => $this->makeMapField(),
                        'percentage' => $this->makeMapField('discount'),
                        'applies_to' => [],
                    ],
                ],
                'payment_schedules' => [],
            ],
        ]);
        $import->mapping_valid = $import->hasValidMapping();
        $import->setTotalRecords();
        $import->save();
        $contents = $import->getImportContents();

        // Create the students fresh based on the student number
        $contents->each(function ($row) {
            Student::factory()->create([
                'tenant_id' => $this->tenant->id,
                'school_id' => $this->school->id,
                'student_number' => $row['student number'],
            ]);
        });

        ray()->measure();
        $job = new ProcessInvoiceImport($import);
        $job->handle();
        ray()->measure();

        $import->refresh();

        $this->assertEquals($contents->count(), $import->invoices()->count());
        $this->assertEquals($contents->count(), $import->imported_records);
        $this->assertEquals(0, $import->failed_records);
        $this->assertCount($contents->count(), $import->results);
        Event::assertDispatched(InvoiceImportFinished::class);
    }

    public function test_can_import_complex_xlsx()
    {
        Storage::fake();
        Event::fake();
        Bus::fake();

        $originalPath = (new InvoiceImport)->storeFile(
            $this->getUploadedFile('complex.xlsx'),
            $this->school
        );

        // Seed a fee to make sure it gets referenced
        $this->school->fees()->save(
            Fee::factory()->make([
                'id' => 1,
                'tenant_id' => $this->tenant->id,
            ])
        );

        // Seed a scholarship for reference's sake
        $this->school->scholarships()
            ->save(
                Scholarship::factory()->make([
                    'id' => 1,
                    'tenant_id' => $this->tenant->id,
                ])
            );

        $firstItem = $this->uuid();
        $import = InvoiceImport::make([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => $originalPath,
            'heading_row' => 2,
            'starting_row' => 3,
            'mapping' => [
                'student_attribute' => 'student_number',
                'student_column' => 'student number',
                'title' => $this->makeMapField('invoice name'),
                'description' => $this->makeMapField(),
                'invoice_date' => $this->makeMapField(value: '2021-08-11T06:17:20.933Z', isManual: true),
                'due_at' => $this->makeMapField('due date'),
                'available_at' => $this->makeMapField('available date'),
                'term_id' => $this->makeMapField(),
                'notify' => false,
                'items' => [
                    [
                        'id' => $firstItem,
                        'fee_id' => $this->makeMapField('item 1 fee'),
                        'name' => $this->makeMapField('item 1 name'),
                        'amount_per_unit' => $this->makeMapField('item 1 amount'),
                        'quantity' => $this->makeMapField(value: 1, isManual: true),
                    ],
                    [
                        'id' => $this->uuid(),
                        'fee_id' => $this->makeMapField(),
                        'name' => $this->makeMapField('item 2 name'),
                        'amount_per_unit' => $this->makeMapField('item 2 amount'),
                        'quantity' => $this->makeMapField(value: 1, isManual: true),
                    ],
                ],
                'scholarships' => [
                    [
                        'id' => $this->uuid(),
                        'scholarship_id' => $this->makeMapField('discount 1 scholarship'),
                        'name' => $this->makeMapField('discount 1 name'),
                        'use_amount' => false,
                        'amount' => $this->makeMapField(),
                        'percentage' => $this->makeMapField('discount 1 percentage'),
                        'applies_to' => [$firstItem],
                    ],
                    [
                        'id' => $this->uuid(),
                        'scholarship_id' => $this->makeMapField(),
                        'name' => $this->makeMapField('discount 2 name'),
                        'use_amount' => true,
                        'amount' => $this->makeMapField('discount 2 amount'),
                        'percentage' => $this->makeMapField(),
                        'applies_to' => [],
                    ],
                ],
                'payment_schedules' => [
                    [
                        'id' => $this->uuid(),
                        'terms' => [
                            [
                                'id' => $this->uuid(),
                                'use_amount' => false,
                                'amount' => $this->makeMapField(),
                                'percentage' => $this->makeMapField('payment 1 percentage'),
                                'due_at' => $this->makeMapField('payment 1 due'),
                            ],
                            [
                                'id' => $this->uuid(),
                                'use_amount' => true,
                                'amount' => $this->makeMapField('payment 2 amount'),
                                'percentage' => $this->makeMapField(),
                                'due_at' => $this->makeMapField('payment 2 due'),
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $import->mapping_valid = $import->hasValidMapping();
        $import->setTotalRecords();
        $import->save();

        // Change the students to match the student numbers
        $students = $this->school->students->random($import->total_records);

        $import->getImportContents()
            ->each(function (Collection $row, $index) use ($students) {
                $students->get($index)->update(['student_number' => $row->get('student number')]);
            });

        ray()->measure();
        $job = new ProcessInvoiceImport($import);
        ray()->countQueries(fn () => $job->handle());
        ray()->measure();

        $import->refresh();
        $contents = $import->getImportContents()->keyBy('student number');

        // Loop over dynamic where it makes sense
        $students->each(function (Student $student, int $index) use ($contents) {
            $row = $contents->get($student->student_number);
            $this->assertEquals(1, $student->invoices->count());

            /** @var Invoice $invoice */
            $invoice = $student->invoices->first();
            $this->assertEquals($row['invoice name'], $invoice->title);
            $this->assertTrue(
                Carbon::create(2021, 10, 1 + $index, 0, 0, 0, $this->user->timezone)
                    ->equalTo($invoice->due_at)
            );
            $this->assertTrue(
                Carbon::create(2021, 9, 1, 0, 0, 0, $this->user->timezone)
                    ->equalTo($invoice->available_at)
            );
            $this->assertEquals(
                Carbon::parse('2021-08-11T06:17:20.933Z')->setTimezone($this->user->timezone)
                    ->format('Y-m-d'),
                $invoice->invoice_date->format('Y-m-d')
            );

            if (empty($row['item 1 fee'])) {
                $this->assertTrue($invoice->invoiceItems->every(fn ($item) => is_null($item->fee_id)));
            } else {
                $this->assertNotNull($invoice->invoiceItems->firstWhere('fee_id', $row['item 1 fee']));
            }

            if (! empty($row['item 1 amount'])) {
                $this->assertNotNull($invoice->invoiceItems->firstWhere('amount', $row['item 1 amount'] * 100));
                $this->assertNotNull($invoice->invoiceItems->firstWhere('name', $row['item 1 name']));
            }

            if (! empty($row['item 2 amount'])) {
                $this->assertNotNull($invoice->invoiceItems->firstWhere('amount', $row['item 2 amount'] * 100));
                $this->assertNotNull($invoice->invoiceItems->firstWhere('name', $row['item 2 name']));
            }

            if (! empty($row['discount 1 percentage'])) {
                $this->assertNotNull($invoice->invoiceScholarships->firstWhere('percentage', $row['discount 1 percentage']));
                $this->assertNotNull($invoice->invoiceScholarships->firstWhere('name', $row['discount 1 name']));
            }

            if (! empty($row['discount 1 scholarship'])) {
                $this->assertNotNull($invoice->invoiceScholarships->firstWhere('scholarship_id', $row['discount 1 scholarship']));
            }

            if (! empty($row['discount 2 amount'])) {
                $this->assertNotNull($invoice->invoiceScholarships->firstWhere('amount', $row['discount 2 amount'] * 100));
                $this->assertNotNull($invoice->invoiceScholarships->firstWhere('name', $row['discount 2 name']));
            }
        });

        // Assert individual student's invoice since they're so different
        // If complex.xlsx changes, these assertions need updated too

        // Student 1
        /** @var Student $student1 */
        $student1 = $students->firstWhere('student_number', 1);
        /** @var Invoice $invoice1 */
        $invoice1 = $student1->invoices->first();
        $this->assertEquals(1, $invoice1->invoiceItems->count());
        $this->assertEquals(120000, $invoice1->amount_due);
        $this->assertEquals(120000, $invoice1->remaining_balance);

        // Student 2
        /** @var Student $student1 */
        $student2 = $students->firstWhere('student_number', 2);
        /** @var Invoice $invoice1 */
        $invoice2 = $student2->invoices->first();
        $this->assertEquals(2, $invoice2->invoiceItems->count());
        $this->assertEquals(1, $invoice2->invoicePaymentTerms->count());
        $this->assertEquals(1, $invoice2->invoiceScholarships->count());
        $this->assertEquals(141500, $invoice2->amount_due);
        $this->assertEquals(141500, $invoice2->remaining_balance);

        // Student 3
        /** @var Student $student1 */
        $student3 = $students->firstWhere('student_number', 3);
        /** @var Invoice $invoice1 */
        $invoice3 = $student3->invoices->first();
        $this->assertEquals(1, $invoice3->invoiceItems->count());
        $this->assertEquals(1, $invoice3->invoicePaymentSchedules->count());
        /** @var InvoicePaymentSchedule $paymentSchedule3 */
        $paymentSchedule3 = $invoice3->invoicePaymentSchedules->first();
        $this->assertEquals(2, $paymentSchedule3->invoicePaymentTerms->count());
        /** @var InvoicePaymentTerm $firstTerm3 */
        $firstTerm3 = $paymentSchedule3->invoicePaymentTerms->first();
        $this->assertEquals(.52, $firstTerm3->percentage);
        $this->assertTrue(empty($firstTerm3->amount));
        $this->assertEquals(39000, $firstTerm3->amount_due);
        $this->assertEquals(39000, $firstTerm3->remaining_balance);
        /** @var InvoicePaymentTerm $secondTerm3 */
        $secondTerm3 = $paymentSchedule3->invoicePaymentTerms->last();
        $this->assertTrue(empty((int) $secondTerm3->percentage));
        $this->assertEquals(55000, $secondTerm3->amount);
        $this->assertEquals(55000, $secondTerm3->amount_due);
        $this->assertEquals(55000, $secondTerm3->remaining_balance);

        $this->assertEquals(2, $invoice3->invoiceScholarships->count());
        $this->assertEquals(75000, $invoice3->amount_due);
        $this->assertEquals(75000, $invoice3->remaining_balance);

        // Import results check
        $this->assertEquals(3, $import->imported_records);
        $this->assertEquals(0, $import->failed_records);
        $this->assertCount(3, $import->results);
        $this->assertTrue(collect($import->results)->every(fn ($r) => $r['successful']));
        Event::assertDispatched(InvoiceImportFinished::class);
    }

    public function test_can_tax_mapping_validation()
    {
        $this->school->update([
            'collect_tax' => true,
            'tax_rate' => 0.05,
            'tax_label' => 'Taxes',
        ]);

        $originalPath = (new InvoiceImport)->storeFile(
            $this->getUploadedFile(),
            $this->school
        );
        $mapping = [
            'student_attribute' => 'student_number',
            'student_column' => 'student number',
            'title' => $this->makeMapField(value: 'Invoice title', isManual: true),
            'description' => $this->makeMapField(),
            'due_at' => $this->makeMapField('due date'),
            'available_at' => $this->makeMapField('available date'),
            'term_id' => $this->makeMapField(),
            'notify' => false,
            'items' => [
                [
                    'fee_id' => $this->makeMapField(),
                    'name' => $this->makeMapField('invoice name'),
                    'amount_per_unit' => $this->makeMapField('invoice amount'),
                    'quantity' => $this->makeMapField(value: 1, isManual: true),
                ],
            ],
            'scholarships' => [
                [
                    'name' => $this->makeMapField(value: 'Assistance', isManual: true),
                    'use_amount' => false,
                    'amount' => $this->makeMapField(),
                    'percentage' => $this->makeMapField('discount'),
                    'applies_to' => [],
                ],
            ],
            'payment_schedules' => [],
            'apply_tax' => true,
            'use_school_tax_defaults' => false,
            'tax_rate' => $this->makeMapField(),
            'tax_label' => $this->makeMapField(),
            'apply_tax_to_all_items' => true,
            'tax_items' => [],
        ];
        $import = InvoiceImport::create([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => $originalPath,
            'heading_row' => 2,
            'starting_row' => 3,
            'mapping' => $mapping,
        ]);

        $errors = $import->getMappingValidationErrors();
        $this->assertCount(2, $errors);
        $this->assertFalse($import->hasValidMapping());
        $this->assertTrue(in_array('tax_rate', array_keys($errors)));
        $this->assertTrue(in_array('tax_label', array_keys($errors)));

        $mapping = $import->mapping;
        unset($mapping['apply_tax']);
        $import->mapping = $mapping;
        $errors = $import->getMappingValidationErrors();
        $this->assertCount(1, $errors);
        $this->assertTrue(in_array('apply_tax', array_keys($errors)));

        $mapping['apply_tax'] = false;
        $import->mapping = $mapping;
        $this->assertTrue($import->hasValidMapping());
        $this->assertEmpty($import->getMappingValidationErrors());
    }

    public function test_can_import_individual_item_tax_rate()
    {
        $this->withoutExceptionHandling();
        Storage::fake();
        Event::fake();
        Bus::fake();

        $this->school->update([
            'collect_tax' => true,
            'tax_rate' => 0.05,
            'tax_label' => 'Taxes',
        ]);

        $originalPath = (new InvoiceImport)->storeFile(
            $this->getUploadedFile('taxed.xls'),
            $this->school
        );
        /** @var Term $term */
        $term = $this->school->terms()
            ->save(
                Term::factory()->make()
            );

        $itemId1 = $this->uuid();
        $itemId2 = $this->uuid();
        $import = InvoiceImport::make([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => $originalPath,
            'heading_row' => 1,
            'starting_row' => 2,
            'mapping' => [
                'student_attribute' => 'student_number',
                'student_column' => 'student number',
                'title' => $this->makeMapField('invoice name'),
                'description' => $this->makeMapField(),
                'invoice_date' => $this->makeMapField('invoice date'),
                'due_at' => $this->makeMapField('due date'),
                'available_at' => $this->makeMapField('available date'),
                'term_id' => $this->makeMapField(null, $term->id, true),
                'notify' => false,
                'items' => [
                    [
                        'id' => $itemId1,
                        'fee_id' => $this->makeMapField(),
                        'name' => $this->makeMapField(value: 'item 1', isManual: true),
                        'amount_per_unit' => $this->makeMapField('item 1'),
                        'quantity' => $this->makeMapField(value: 1, isManual: true),
                    ],
                    [
                        'id' => $itemId2,
                        'fee_id' => $this->makeMapField(),
                        'name' => $this->makeMapField(value: 'item 2', isManual: true),
                        'amount_per_unit' => $this->makeMapField('item 2'),
                        'quantity' => $this->makeMapField(value: 1, isManual: true),
                    ],
                ],
                'scholarships' => [
                    [
                        'id' => $this->uuid(),
                        'name' => $this->makeMapField(value: 'Assistance 1', isManual: true),
                        'use_amount' => false,
                        'amount' => $this->makeMapField(),
                        'percentage' => $this->makeMapField('discount'),
                        'applies_to' => [$itemId1],
                    ],
                    [
                        'id' => $this->uuid(),
                        'name' => $this->makeMapField(value: 'Assistance 2', isManual: true),
                        'use_amount' => true,
                        'amount' => $this->makeMapField('discount amount'),
                        'percentage' => $this->makeMapField(),
                        'applies_to' => [],
                    ],
                ],
                'payment_schedules' => [],
                'apply_tax' => true,
                'use_school_tax_defaults' => true,
                'tax_rate' => $this->makeMapField(),
                'tax_label' => $this->makeMapField(),
                'apply_tax_to_all_items' => false,
                'tax_items' => [
                    [
                        'item_id' => $itemId1,
                        'selected' => true,
                        'tax_rate' => $this->makeMapField('tax rate'),
                    ],
                    [
                        'item_id' => $itemId2,
                        'selected' => false,
                        'tax_rate' => $this->makeMapField(value: 0.05, isManual: true),
                    ],
                ],
            ],
        ]);
        $import->mapping_valid = $import->hasValidMapping();
        $import->setTotalRecords();
        $import->save();

        // Change the students to match the student numbers
        $students = $this->school->students->random($import->total_records);

        $import->getImportContents()
            ->each(function (Collection $row, $index) use ($students) {
                $studentNumber = $row->get('student number');

                if (! blank($studentNumber)) {
                    $students->get($index)->update(['student_number' => $row->get('student number')]);
                }
            });

        (new ProcessInvoiceImport($import))
            ->handle();

        $import->refresh();
        $contents = $import->getImportContents();
        $contentsByStudentNumber = $contents->keyBy('student number');

        $values = $contents
            ->pluck('student number')
            ->filter(fn ($value) => ! is_null($value));

        $students = $import->school->students()
            ->whereIn('student_number', $values)
            ->orderByDesc('student_number')
            ->get();

        $students->each(function (Student $student) use ($contentsByStudentNumber, $term) {
            $this->assertEquals(1, $student->invoices->count());
            /** @var Invoice $invoice */
            $invoice = $student->invoices->first();

            $row = $contentsByStudentNumber->get($student->student_number);

            // Check invoice items and subtotal
            $subtotal = $row['item 1'] * 100 + $row['item 2'] * 100;
            $items = ['item 1', 'item 2'];
            $this->assertEquals(2, $invoice->invoiceItems->count());
            $this->assertEquals($subtotal, $invoice->subtotal);

            foreach ($items as $item) {
                /** @var InvoiceItem $item */
                $invoiceItem = $invoice->invoiceItems->firstWhere('name', $item);
                $this->assertEquals($row[$item] * 100, $invoiceItem->amount);
                $this->assertEquals($row[$item] * 100, $invoiceItem->amount_per_unit);
                $this->assertEquals(1, $invoiceItem->quantity);
            }

            // Check scholarships and discount total
            $discount = 0;
            $this->assertTrue($invoice->invoiceScholarships->count() > 0);

            // This discount only applies to item 1
            if (! empty($row['discount'])) {
                /** @var InvoiceScholarship $scholarship1 */
                $scholarship1 = $invoice->invoiceScholarships->firstWhere('name', 'Assistance 1');
                $this->assertEquals($row['discount'], $scholarship1->percentage);
                $this->assertEquals(0, $scholarship1->amount);
                $this->assertEquals($row['item 1'] * 100 * $row['discount'], $scholarship1->calculated_amount);
                $discount += $scholarship1->calculated_amount;
            }

            if (! empty($row['discount amount'])) {
                /** @var InvoiceScholarship $scholarship2 */
                $scholarship2 = $invoice->invoiceScholarships->firstWhere('name', 'Assistance 2');

                $this->assertEquals(0, $scholarship2->percentage);
                $this->assertEquals($row['discount amount'] * 100, $scholarship2->amount);
                $this->assertEquals($row['discount amount'] * 100, $scholarship2->calculated_amount);
                $discount += $scholarship2->calculated_amount;
            }

            $pretax = $subtotal - $discount;
            if ($pretax < 0) {
                $pretax = 0;
            }

            $this->assertEquals($term->id, $invoice->term_id);
            $this->assertEquals($pretax, $invoice->pre_tax_subtotal);
            $this->assertEquals(1, $invoice->invoiceTaxItems->count());

            // The tax configuration is that the tax only applies to item 1
            $itemSubtotal = $row['item 1'] * 100;
            $itemDiscount1 = empty($row['discount'])
                ? 0
                : ($itemSubtotal * $row['discount']);
            $ratio = $itemSubtotal / $subtotal;
            $itemDiscount2 = $row['discount amount'] * 100 * $ratio;
            $itemPretax = $itemSubtotal - $itemDiscount1 - $itemDiscount2;

            $tax = round($itemPretax * $row['tax rate']);

            /** @var InvoiceTaxItem $taxItem */
            $taxItem = $invoice->invoiceTaxItems->first();
            /** @var InvoiceItem $invoiceItem */
            $invoiceItem = $invoice->invoiceItems->firstWhere('name', 'item 1');

            $this->assertEquals($invoiceItem->uuid, $taxItem->invoice_item_uuid);
            $this->assertEquals($row['tax rate'], $taxItem->tax_rate);
            $this->assertEquals($tax, $invoice->tax_due);
            $this->assertEquals($this->school->tax_rate, $invoice->tax_rate);
            $this->assertEquals($this->school->tax_label, $invoice->tax_label);

            $due = $pretax + $tax;

            $this->assertEquals($due, $invoice->amount_due);
            $this->assertEquals($due, $invoice->remaining_balance);
            $this->assertNotNull($invoice->invoiceImport);
            $this->assertTrue(
                Carbon::parse(self::FIXTURE_DUE_DATES_BY_STUDENT_NUMBER[$student->student_number], $this->user->timezone)
                    ->equalTo($invoice->due_at)
            );
            $this->assertTrue(
                Carbon::create(2021, 9, 1, 8, 0, 0, $this->user->timezone)
                    ->equalTo($invoice->available_at)
            );
            $this->assertTrue(
                Carbon::create(2021, 9)
                    ->equalTo($invoice->invoice_date)
            );
        });

        $this->assertEquals(3, $import->imported_records);
        $this->assertEquals(1, $import->failed_records);
        $this->assertCount(4, $import->results);
        Event::assertDispatched(InvoiceImportFinished::class);
    }

    public function test_will_skip_scholarship_without_name()
    {
        $this->withoutExceptionHandling();
        Storage::fake();
        Event::fake();
        Bus::fake();
        Queue::fake();

        $originalPath = (new InvoiceImport)->storeFile(
            $this->getUploadedFile('sonar-import.xls'),
            $this->school
        );
        /** @var Term $term */
        $term = $this->school->terms()
            ->save(
                Term::factory()->make()
            );
        $import = InvoiceImport::make([
            'user_uuid' => $this->user->id,
            'school_id' => $this->school->id,
            'file_path' => $originalPath,
            'heading_row' => 1,
            'starting_row' => 2,
            'mapping' => [
                'student_attribute' => 'student_number',
                'student_column' => 'student number',
                'title' => $this->makeMapField(value: 'Invoice title', isManual: true),
                'description' => $this->makeMapField(),
                'invoice_date' => $this->makeMapField('invoice date'),
                'due_at' => $this->makeMapField('due date'),
                'available_at' => $this->makeMapField('available date'),
                'term_id' => $this->makeMapField(null, $term->id, true),
                'notify' => false,
                'items' => [
                    [
                        'id' => $this->uuid(),
                        'fee_id' => $this->makeMapField(),
                        'name' => $this->makeMapField('invoice name'),
                        'amount_per_unit' => $this->makeMapField('invoice amount'),
                        'quantity' => $this->makeMapField(value: 1, isManual: true),
                    ],
                ],
                'scholarships' => [
                    [
                        'id' => $this->uuid(),
                        'name' => $this->makeMapField(value: '', isManual: true),
                        'use_amount' => false,
                        'amount' => $this->makeMapField(),
                        'percentage' => $this->makeMapField('discount'),
                        'applies_to' => [],
                    ],
                ],
                'payment_schedules' => [],
            ],
        ]);
        $import->mapping_valid = $import->hasValidMapping();
        $import->setTotalRecords();
        $import->save();

        // Change the students to match the student numbers
        $students = $this->school->students->random($import->total_records);

        $import->getImportContents()
            ->each(function (Collection $row, $index) use ($students) {
                $studentNumber = $row->get('student number');

                if (! blank($studentNumber)) {
                    $students->get($index)->update(['student_number' => $row->get('student number')]);
                }
            });

        $job = new ProcessInvoiceImport($import);
        $job->handle();

        $import->refresh();
        $contents = $import->getImportContents();
        $contentsByStudentNumber = $contents->keyBy('student number');

        $values = $contents
            ->pluck('student number')
            ->filter(fn ($value) => ! is_null($value));

        $students = $import->school->students()
            ->whereIn('student_number', $values)
            ->orderByDesc('student_number')
            ->get();

        $students->each(function (Student $student) use ($import, $contentsByStudentNumber, $term) {
            $this->assertEquals(1, $student->invoices->count());
            /** @var Invoice $invoice */
            $invoice = $student->invoices->first();
            $this->assertEquals(1, $invoice->invoiceItems->count());
            $row = $contentsByStudentNumber->get($student->student_number);
            $total = $row['invoice amount'] * 100;
            $discount = 0;

            if (! empty($row['discount'])) {
                $this->assertEquals(0, $invoice->invoiceScholarships->count());
                $result = Arr::first($import->results, fn ($e) => $e['result'] === $invoice->uuid);
                $this->assertCount(1, $result['warnings']);
            }

            /** @var InvoiceItem $item */
            $item = $invoice->invoiceItems->first();
            $this->assertEquals($total, $item->amount);
            $this->assertEquals($total, $item->amount_per_unit);

            $due = $total - $discount;
            if ($due < 0) {
                $due = 0;
            }
            $this->assertNotNull($invoice->invoice_number);
            $this->assertEquals($term->id, $invoice->term_id);
            $this->assertEquals($due, $invoice->amount_due);
            $this->assertEquals($due, $invoice->remaining_balance);
            $this->assertEquals($total, $invoice->subtotal);
            $this->assertEquals($discount, $invoice->discount_total);
            $this->assertNotNull($invoice->invoiceImport);
            $this->assertTrue(
                Carbon::parse(self::FIXTURE_DUE_DATES_BY_STUDENT_NUMBER[$student->student_number], $this->user->timezone)
                    ->equalTo($invoice->due_at)
            );
            $this->assertTrue(
                Carbon::create(2021, 9, 1, 8, 0, 0, $this->user->timezone)
                    ->equalTo($invoice->available_at)
            );
            $this->assertEquals(
                '2021-09-01',
                $invoice->invoice_date->format('Y-m-d')
            );
        });

        Bus::assertBatched(function (PendingBatchFake $batch) {
            return $batch->jobs->count() === 3;
        });
        $this->assertNotNull($import->pdf_batch_id);

        $this->assertEquals(3, $import->imported_records);
        $this->assertEquals(1, $import->failed_records);
        $this->assertCount(4, $import->results);
        $this->assertEquals(3, Activity::count());
        Event::assertDispatched(InvoiceImportFinished::class);
    }
}
