<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceLayout;
use App\Models\School;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PortalPublisher;
use Illuminate\Database\Seeder;

class SonarPortalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->isLocal()) {
            throw new \RuntimeException('Invoice demo fixtures are local-only.');
        }
        $this->call(CurrencySeeder::class);
        $tenant = Tenant::firstOrCreate(['domain' => 'host.docker.internal'], ['name' => 'Invoice Demo', 'license' => (string) \Illuminate\Support\Str::uuid(), 'allow_password_auth' => true]);
        $tenant->makeCurrent();
        $tenant->forceFill(['ps_url' => 'https://powerschool.invalid', 'ps_client_id' => 'local-demo', 'ps_secret' => 'local-demo'])->save();
        $school = $tenant->schools()->where('sis_id', 990099)->first() ?? $tenant->schools()->save(School::factory()->make(['sis_id' => 990099, 'name' => 'Sonar Invoice Demo']));
        app()->instance(School::class, $school);
        $this->command->info('Demo tenant id: '.$tenant->id);
        $student = $school->students()->where('crm_reference', '77f40c5a-51f0-4f60-8dc5-44f6fdb18962')->first();
        if (! $student) {
            $this->command->info('Run CRM sonar:reconcile, then rerun this seeder to publish the demo invoice.');

            return;
        }
        $user = $tenant->users()->where('email', 'sonar-finance@example.test')->first() ?? $tenant->users()->save(User::factory()->make(['email' => 'sonar-finance@example.test', 'school_id' => $school->id]));
        \Bouncer::allow($user)->everything();
        \Illuminate\Support\Facades\Auth::login($user);
        if (! $school->invoiceLayouts()->exists()) {
            InvoiceLayout::factory()->create(['school_id' => $school->id, 'tenant_id' => $tenant->id, 'name' => 'Demo invoice layout', 'paper_size' => 'A4']);
        }
        $invoice = $school->invoices()->where('invoice_number', 'DEMO-APPLICANT-001')->first();
        if (! $invoice) {
            $invoice = Invoice::factory()->create([
                'user_uuid' => $user->uuid, 'student_uuid' => $student->uuid, 'invoice_number' => 'DEMO-APPLICANT-001',
                'title' => 'Application assessment fee', 'description' => 'Local demonstration invoice for an applicant without a PowerSchool ID.',
                'published_at' => now()->subMinutes(20), 'available_at' => null, 'due_at' => now()->addDays(14),
                'notify' => true, 'notify_at' => now()->subMinute(), 'amount_due' => 50000, 'remaining_balance' => 50000, 'apply_tax' => false,
            ]);
            $invoice->invoiceItems()->save(InvoiceItem::factory()->make(['name' => 'Assessment', 'quantity' => 1, 'amount_per_unit' => 50000]));
        }
        $publisher = app(PortalPublisher::class);
        $invoice->forceFill(['grade_level_adjustment' => 0])->save();
        $revision = $publisher->snapshot($invoice)['revision'];
        $invoice->savePdf()->forceFill(['portal_revision' => $revision])->save();
        $publisher->capture($invoice);
        $this->command->info('Published demo invoice: '.$invoice->uuid);
    }
}
