<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalInvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $signIn = true;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.crm_portal.token' => 'portal-secret']);
    }

    public function test_portal_invoice_api_requires_the_integration_token(): void
    {
        $student = $this->createStudent(['sis_id' => 1001]);

        $this->getJson('/api/portal/v1/invoices?school_sis_id='.$this->school->sis_id.'&student_sis_ids[]=1001')
            ->assertUnauthorized();
    }

    public function test_portal_invoice_api_only_returns_current_invoices_for_allowed_students(): void
    {
        $allowedStudent = $this->createStudent(['sis_id' => 1001]);
        $otherStudent = $this->createStudent(['sis_id' => 1002]);
        $visible = Invoice::factory()->create(['user_uuid' => $this->user->uuid,
            'student_uuid' => $allowedStudent->uuid,
            'amount_due' => 10000,
            'remaining_balance' => 7500,
            'available_at' => now()->subMinute(),
        ]);
        Invoice::factory()->create(['user_uuid' => $this->user->uuid,
            'student_uuid' => $allowedStudent->uuid,
            'available_at' => now()->addDay(),
        ]);
        Invoice::factory()->create(['user_uuid' => $this->user->uuid,
            'student_uuid' => $allowedStudent->uuid,
            'published_at' => null,
        ]);
        Invoice::factory()->create(['user_uuid' => $this->user->uuid, 'student_uuid' => $otherStudent->uuid]);

        $this->withToken('portal-secret')
            ->getJson('/api/portal/v1/invoices?school_sis_id='.$this->school->sis_id.'&student_sis_ids[]=1001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $visible->uuid)
            ->assertJsonPath('summary.invoice_count', 1)
            ->assertJsonMissingPath('data.0.activities');
    }

    public function test_combined_invoice_requires_access_to_every_child_student(): void
    {
        $firstStudent = $this->createStudent(['sis_id' => 1001]);
        $secondStudent = $this->createStudent(['sis_id' => 1002]);
        $parent = Invoice::factory()->create(['user_uuid' => $this->user->uuid,
            'student_uuid' => null,
            'is_parent' => true,
        ]);
        Invoice::factory()->create(['user_uuid' => $this->user->uuid,
            'student_uuid' => $firstStudent->uuid,
            'parent_uuid' => $parent->uuid,
        ]);
        Invoice::factory()->create(['user_uuid' => $this->user->uuid,
            'student_uuid' => $secondStudent->uuid,
            'parent_uuid' => $parent->uuid,
        ]);

        $baseUrl = '/api/portal/v1/invoices?school_sis_id='.$this->school->sis_id;

        $this->withToken('portal-secret')
            ->getJson($baseUrl.'&student_sis_ids[]=1001')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken('portal-secret')
            ->getJson($baseUrl.'&student_sis_ids[]=1001&student_sis_ids[]=1002')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $parent->uuid)
            ->assertJsonCount(2, 'data.0.children');
    }

    public function test_portal_can_download_an_allowed_invoice_pdf(): void
    {
        Storage::fake(config('filesystems.invoices'));
        $student = $this->createStudent(['sis_id' => 1001]);
        $invoice = Invoice::factory()->create(['user_uuid' => $this->user->uuid, 'student_uuid' => $student->uuid]);
        $invoice->fakeSavePdf();

        $this->withToken('portal-secret')
            ->get('/api/portal/v1/invoices/'.$invoice->uuid.'/pdf?school_sis_id='.$this->school->sis_id.'&student_sis_ids[]=1001')
            ->assertOk()
            ->assertDownload($invoice->invoice_number.'.pdf');
    }
}
