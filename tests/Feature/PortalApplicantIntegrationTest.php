<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Services\PortalPublisher;
use App\Services\PortalStudentIdentity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalApplicantIntegrationTest extends TestCase
{
    protected bool $signIn = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.crm_portal.tenant_id' => $this->tenant->id, 'services.crm_portal.school_sis_ids' => [(int) $this->school->sis_id], 'services.crm_portal.token' => 'portal-secret', 'services.crm_portal.enabled' => false]);
        Http::preventStrayRequests();
    }

    private function applicant(): \App\Models\Student
    {
        return app(PortalStudentIdentity::class)->upsert($this->school, [
            'crm_reference' => (string) Str::uuid(), 'sis_id' => null,
            'first_name' => 'Applicant', 'last_name' => 'Student', 'grade_level' => 5,
        ]);
    }

    public function test_only_authenticated_portal_requests_receive_the_integration_rate_limit(): void
    {
        $limiter = \Illuminate\Support\Facades\RateLimiter::limiter('api');
        $request = \Illuminate\Http\Request::create('/api/portal/v2/events');
        $this->assertSame(60, $limiter($request)->maxAttempts);
        $request->headers->set('Authorization', 'Bearer incorrect');
        $this->assertSame(60, $limiter($request)->maxAttempts);
        $request->headers->set('Authorization', 'Bearer portal-secret');
        $this->assertSame(600, $limiter($request)->maxAttempts);
        $other = \Illuminate\Http\Request::create('/api/unrelated');
        $other->headers->set('Authorization', 'Bearer portal-secret');
        $this->assertSame(60, $limiter($other)->maxAttempts);
    }

    public function test_applicant_can_be_upserted_without_sis_and_selected_for_invoicing(): void
    {
        $reference = (string) Str::uuid();
        $data = ['school_sis_id' => $this->school->sis_id, 'crm_reference' => $reference, 'first_name' => 'New', 'last_name' => 'Applicant', 'grade_level' => 5, 'sis_id' => null];
        $first = $this->withToken('portal-secret')->putJson('/api/portal/v2/students/'.$reference, $data)->assertOk()->json('data.uuid');
        $this->withToken('portal-secret')->putJson('/api/portal/v2/students/'.$reference, $data)->assertJsonPath('data.uuid', $first);
        $student = $this->school->students()->where('crm_reference', $reference)->sole();
        $this->assertNull($student->sis_id);
        $this->assertFalse((bool) $student->enrolled);
        $this->assertTrue($this->school->students()->filter(['status' => 'applicants'])->whereKey($student->uuid)->exists());
    }

    public function test_local_tenant_login_redirect_keeps_the_managed_port(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['app.url' => 'http://localhost:20167']);
        (new \App\Providers\AppServiceProvider($this->app))->boot();
        \App\Models\Tenant::forgetCurrent();
        $this->app->instance('request', \Illuminate\Http\Request::create('http://'.$this->tenant->domain.':20167/students'));
        $this->tenant->makeCurrent();
        $this->assertSame('http://'.$this->tenant->domain.':20167/login', route('login'));
    }

    public function test_sis_binding_preserves_student_uuid_and_financial_records(): void
    {
        $student = $this->applicant();
        $invoice = Invoice::factory()->create(['user_uuid' => $this->user->uuid, 'student_uuid' => $student->uuid]);
        $bound = app(PortalStudentIdentity::class)->upsert($this->school, ['crm_reference' => $student->crm_reference, 'sis_id' => 990001]);
        $this->assertSame($student->uuid, $bound->uuid);
        $this->assertSame($student->uuid, $invoice->fresh()->student_uuid);
    }

    public function test_exact_sis_match_backfills_existing_student(): void
    {
        $student = $this->createStudent(['sis_id' => 990002]);
        $bound = app(PortalStudentIdentity::class)->upsert($this->school, ['crm_reference' => (string) Str::uuid(), 'sis_id' => 990002, 'first_name' => 'Must not overwrite']);
        $this->assertSame($student->uuid, $bound->uuid);
        $this->assertSame($student->first_name, $bound->first_name);
    }

    public function test_conflict_requires_confirmed_reconciliation_and_preserves_both_invoices(): void
    {
        $applicant = $this->applicant();
        $duplicate = $this->createStudent(['sis_id' => 990003]);
        $first = Invoice::factory()->create(['user_uuid' => $this->user->uuid, 'student_uuid' => $applicant->uuid]);
        $second = Invoice::factory()->create(['user_uuid' => $this->user->uuid, 'student_uuid' => $duplicate->uuid]);
        $this->withToken('portal-secret')->putJson('/api/portal/v2/students/'.$applicant->crm_reference, [
            'school_sis_id' => $this->school->sis_id, 'crm_reference' => $applicant->crm_reference,
            'sis_id' => 990003, 'first_name' => 'Applicant', 'last_name' => 'Student',
        ])->assertStatus(409);
        app(PortalStudentIdentity::class)->reconcile($this->school, $applicant->crm_reference, $duplicate->uuid);
        $this->assertSame($applicant->uuid, $first->fresh()->student_uuid);
        $this->assertSame($applicant->uuid, $second->fresh()->student_uuid);
        $this->assertSame($applicant->uuid, $duplicate->fresh()->merged_into_uuid);
        $this->assertNotNull($duplicate->fresh());
    }

    public function test_sis_import_resolves_crm_identity_before_creating_a_student(): void
    {
        $student = $this->applicant();
        config(['services.crm_portal.enabled' => true, 'services.crm_portal.identity_url' => 'https://crm.test/identity', 'services.crm_portal.webhook_secret' => 'secret']);
        Http::fake(['crm.test/*' => Http::response(['data' => ['crm_reference' => $student->crm_reference]])]);
        $bound = app(PortalStudentIdentity::class)->fromSis($this->school, 990004, ['first_name' => 'SIS name', 'enrolled' => true]);
        $this->assertSame($student->uuid, $bound->uuid);
        $this->assertSame('SIS name', $bound->first_name);
        $this->assertEquals(990004, $bound->sis_id);
    }

    public function test_portal_requires_authorized_references_and_invoice_grants(): void
    {
        $student = $this->applicant();
        $invoice = Invoice::factory()->create(['user_uuid' => $this->user->uuid, 'student_uuid' => $student->uuid]);
        $query = http_build_query(['school_sis_id' => $this->school->sis_id, 'student_refs' => [$student->crm_reference], 'invoice_uuids' => [$invoice->uuid]]);
        $this->withToken('portal-secret')->getJson('/api/portal/v2/invoices/'.$invoice->uuid.'?'.$query)->assertOk()->assertJsonPath('data.uuid', $invoice->uuid);
        $other = $this->applicant();
        $query = http_build_query(['school_sis_id' => $this->school->sis_id, 'student_refs' => [$other->crm_reference], 'invoice_uuids' => [$invoice->uuid]]);
        $this->withToken('portal-secret')->getJson('/api/portal/v2/invoices/'.$invoice->uuid.'?'.$query)->assertNotFound();
    }

    public function test_pdf_download_requires_every_child_and_current_revision(): void
    {
        Storage::fake(config('filesystems.invoices'));
        $first = $this->applicant();
        $second = $this->applicant();
        $parent = Invoice::factory()->create(['user_uuid' => $this->user->uuid, 'student_uuid' => null, 'is_parent' => true]);
        Invoice::factory()->create(['user_uuid' => $this->user->uuid, 'student_uuid' => $first->uuid, 'parent_uuid' => $parent->uuid]);
        Invoice::factory()->create(['user_uuid' => $this->user->uuid, 'student_uuid' => $second->uuid, 'parent_uuid' => $parent->uuid]);
        $query = http_build_query(['school_sis_id' => $this->school->sis_id, 'student_refs' => [$first->crm_reference], 'invoice_uuids' => [$parent->uuid]]);
        $this->withToken('portal-secret')->get('/api/portal/v2/invoices/'.$parent->uuid.'/pdf?'.$query)->assertNotFound();
        $revision = app(PortalPublisher::class)->snapshot($parent)['revision'];
        $this->assertSame($revision, app(PortalPublisher::class)->snapshot($parent->fresh())['revision']);
        $parent->fakeSavePdf()->forceFill(['portal_revision' => $revision])->save();
        $query = http_build_query(['school_sis_id' => $this->school->sis_id, 'student_refs' => [$first->crm_reference, $second->crm_reference], 'invoice_uuids' => [$parent->uuid]]);
        $this->withToken('portal-secret')->get('/api/portal/v2/invoices/'.$parent->uuid.'/pdf?'.$query)
            ->assertOk()->assertHeader('X-Invoice-Revision', $revision);
    }

    public function test_publication_events_are_durable_and_resends_are_explicit(): void
    {
        $student = $this->applicant();
        $invoice = Invoice::factory()->create(['user_uuid' => $this->user->uuid, 'student_uuid' => $student->uuid, 'notify' => true, 'published_at' => now(), 'available_at' => now()->addHour()]);
        config(['services.crm_portal.enabled' => true]);
        $publisher = app(PortalPublisher::class);
        $first = $publisher->capture($invoice);
        $this->assertFalse($first->payload['visible']);
        $request = $first->payload['request_id'];
        $this->assertNotNull($request);
        $this->assertEquals($invoice->available_at->timestamp, strtotime($first->payload['send_at']));
        $this->assertNull($publisher->capture($invoice));
        $invoice->updateQuietly(['title' => 'Updated title']);
        $changed = $publisher->capture($invoice);
        $this->assertSame($request, $changed->payload['request_id']);
        $resend = $publisher->capture($invoice, false, true);
        $this->assertNotSame($request, $resend->payload['request_id']);
        $invoice->updateQuietly(['notify' => false]);
        $this->assertNull($publisher->capture($invoice)->payload['request_id']);
    }

    public function test_combined_charges_for_one_student_require_one_identity(): void
    {
        $student = $this->applicant();
        $parent = Invoice::factory()->create(['user_uuid' => $this->user->uuid, 'student_uuid' => null, 'is_parent' => true]);
        Invoice::factory()->count(2)->create(['user_uuid' => $this->user->uuid, 'student_uuid' => $student->uuid, 'parent_uuid' => $parent->uuid]);
        $snapshot = app(PortalPublisher::class)->snapshot($parent);
        $this->assertSame(1, $snapshot['student_count']);
        $this->assertSame([$student->crm_reference], $snapshot['student_refs']);
    }

    public function test_pdf_revisions_include_rendering_options_and_never_reuse_storage_paths(): void
    {
        $student = $this->applicant();
        $invoice = Invoice::factory()->create(['user_uuid' => $this->user->uuid, 'student_uuid' => $student->uuid, 'grade_level_adjustment' => 0]);
        $publisher = app(PortalPublisher::class);
        $revision = $publisher->snapshot($invoice)['revision'];
        $invoice->updateQuietly(['grade_level_adjustment' => 1]);
        $this->assertNotSame($revision, $publisher->snapshot($invoice)['revision']);
        $this->assertNotSame($invoice->generatePdfPath(), $invoice->generatePdfPath());
    }

    public function test_v2_rejects_wrong_school_and_tenant_scope(): void
    {
        $student = $this->applicant();
        $data = ['school_sis_id' => $this->school->sis_id, 'student_refs' => [$student->crm_reference], 'invoice_uuids' => [(string) Str::uuid()]];
        config(['services.crm_portal.school_sis_ids' => [(int) $this->school->sis_id + 1]]);
        $this->withToken('portal-secret')->postJson('/api/portal/v2/invoices', $data)->assertForbidden();
        config(['services.crm_portal.school_sis_ids' => [(int) $this->school->sis_id], 'services.crm_portal.tenant_id' => $this->tenant->id + 1]);
        $this->withToken('portal-secret')->postJson('/api/portal/v2/invoices', $data)->assertForbidden();
    }

    public function test_backfill_never_requests_an_email(): void
    {
        $student = $this->applicant();
        $invoice = Invoice::factory()->create(['user_uuid' => $this->user->uuid, 'student_uuid' => $student->uuid, 'notify' => true]);
        config(['services.crm_portal.enabled' => true]);
        $publisher = app(PortalPublisher::class);
        $event = $publisher->capture($invoice, true);
        $this->assertNull($event->payload['request_id']);
        $this->assertNull($publisher->capture($invoice)->payload['request_id']);
    }

    public function test_backfill_can_prepare_a_disabled_pilot_and_clear_old_send_requests(): void
    {
        $student = $this->applicant();
        $invoice = Invoice::factory()->create(['user_uuid' => $this->user->uuid, 'student_uuid' => $student->uuid, 'notify' => true]);
        config(['services.crm_portal.enabled' => true]);
        $publisher = app(PortalPublisher::class);
        $this->assertNotNull($publisher->capture($invoice)->payload['request_id']);
        config(['services.crm_portal.enabled' => false]);
        $this->artisan('portal:sync', ['--backfill' => true])->assertSuccessful();
        $this->assertNull(\App\Models\PortalInvoiceState::find($invoice->uuid)->request_id);
        config(['services.crm_portal.enabled' => true]);
        $invoice->updateQuietly(['title' => 'Historical correction']);
        $this->assertNull($publisher->capture($invoice)->payload['request_id']);
    }

    public function test_failed_webhook_is_retained_for_retry_and_cursor_replay(): void
    {
        $student = $this->applicant();
        $invoice = Invoice::factory()->create(['user_uuid' => $this->user->uuid, 'student_uuid' => $student->uuid]);
        config(['services.crm_portal.enabled' => true, 'services.crm_portal.webhook_url' => 'https://crm.test/events', 'services.crm_portal.webhook_secret' => 'secret']);
        $publisher = app(PortalPublisher::class);
        $event = $publisher->capture($invoice);
        Http::fake(['crm.test/*' => Http::response([], 503)]);
        $publisher->deliver($event);
        $this->assertNull($event->fresh()->delivered_at);
        $this->assertSame(1, $event->fresh()->attempts);
        $this->withToken('portal-secret')->getJson('/api/portal/v2/events?school_sis_id='.$this->school->sis_id.'&after=0')
            ->assertOk()->assertJsonPath('data.0.id', $event->id);
    }
}
