<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\RecordExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;
use Tests\Traits\CreatesInvoice;

class RecordsExportTest extends TestCase
{
    use CreatesInvoice;
    use RefreshDatabase;

    protected bool $signIn = true;

    public function test_can_create_a_new_invoice_export()
    {
        $this->assignPermission('view', Invoice::class);
        $data = [
            'name' => Str::random(),
            'format' => 'xlsx',
            'apply_filters' => true,
            'filters' => [
                's' => '',
                'status' => [],
                'date_start' => null,
                'date_end' => null,
            ],
        ];

        $this->post('/export/invoices', $data)
            ->assertRedirect();

        $data['school_id'] = $this->school->id;
        $data['user_uuid'] = $this->user->uuid;
        unset($data['filters']);
        $this->assertDatabaseHas('record_exports', $data);
    }

    public function test_invoice_export_without_global_permission_only_contains_assigned_invoices()
    {
        $hidden = $this->createInvoice(['published_at' => now()]);
        $visible = $this->createInvoice(['published_at' => now()]);
        $visible->users()->attach($this->user->uuid);

        $data = [
            'name' => Str::random(),
            'format' => 'xlsx',
            'apply_filters' => true,
            'filters' => [
                's' => '',
                'status' => [],
                'date_start' => null,
                'date_end' => null,
            ],
        ];

        $this->post('/export/invoices', $data)
            ->assertRedirect();

        $export = RecordExport::where('user_uuid', $this->user->uuid)->firstOrFail();
        $exportedIds = Invoice::getExportQuery($export)->get()->modelKeys();
        $this->assertSame([$visible->uuid], $exportedIds);
        $this->assertNotContains($hidden->uuid, $exportedIds);
    }

    public function test_can_download_invoice_export()
    {
        Excel::fake();

        $export = RecordExport::factory()->create(['user_uuid' => $this->user->uuid]);

        $this->get(route('exports.download', $export));

        Excel::assertDownloaded($export->file_name);
    }
}
