<?php

namespace Tests\Feature;

use App\Jobs\MakeReceipt;
use App\Jobs\SetStudentCachedValues;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoicePaymentTerm;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\ReceiptLayout;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\CreatesInvoice;
use Tests\Traits\CreatesPayments;

class InvoicePaymentTest extends TestCase
{
    use CreatesInvoice;
    use CreatesPayments;
    use RefreshDatabase;

    protected bool $signIn = true;

    protected Filesystem $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = Storage::fake(config('filesystems.receipts'));

        // Create the layout
        ReceiptLayout::factory()->create(['is_default' => true]);
    }

    public function test_cant_list_all_payments_without_permissions()
    {
        $this->get('/payments')
            ->assertForbidden();
    }

    public function test_can_list_payments_with_permission()
    {
        $this->assignPermission('view', InvoicePayment::class);

        $this->get('/payments')
            ->assertInertia(fn (Assert $page) => $page
                ->has('title')
                ->has('payments')
                ->component('payments/Index')
            );
    }

    public function test_can_access_create_page()
    {
        $this->assignPermission('create', InvoicePayment::class);

        $this->get('/payments/create')
            ->assertInertia(fn (Assert $page) => $page
                ->has('title')
                ->has('breadcrumbs')
                ->has('invoice')
                ->has('paidBy')
                ->component('payments/Create')
            );
    }

    public function test_can_save_payment_to_invoice_without_associating_term()
    {
        Queue::fake();
        $this->assignPermission('create', InvoicePayment::class);

        $invoice = $this->createInvoice();
        $date = $this->user->getCarbonFactory()
            ->today()
            ->subDay()
            ->setTimezone(config('app.timezone'));
        $amount = (int) round($invoice->amount_due / 2);

        $this->assertGreaterThan(0, $amount);

        $data = [
            'invoice_uuid' => $invoice->uuid,
            'payment_method_id' => null,
            'paid_at' => $this->getDateForInvoice($date),
            'amount' => $amount,
            'made_by' => null,
            'notes' => $this->faker->sentence(),
            'transaction_details' => $this->faker->words(asText: true),
        ];

        $this->post(route('payments.store'), $data)
            ->assertSessionHas('success')
            ->assertRedirect();

        $invoice->refresh();
        $this->assertEquals(1, $invoice->invoicePayments()->count());
        $this->assertDatabaseHas('invoice_payments', [
            'tenant_id' => $this->tenant->id,
            'school_id' => $this->school->id,
            'invoice_uuid' => $invoice->uuid,
            'amount' => $data['amount'],
            'original_amount' => $data['amount'],
            'notes' => $data['notes'],
            'transaction_details' => $data['transaction_details'],
            'paid_at' => $date->toDateTimeString(),
        ]);
        $this->assertEquals($invoice->amount_due - $data['amount'], $invoice->refresh()->remaining_balance);

        Queue::assertPushed(MakeReceipt::class);
        Queue::assertPushed(SetStudentCachedValues::class, function ($job) use ($invoice) {
            return $job->studentUuid === $invoice->student_uuid;
        });
    }

    public function test_can_save_payment_to_invoice_with_associating_term()
    {
        Queue::fake();
        $this->assignPermission('create', InvoicePayment::class);

        $invoice = $this->createInvoice();
        $this->seedPaymentSchedules($invoice, 1);
        /** @var InvoicePaymentTerm $term */
        $term = $invoice->invoicePaymentTerms()->first();

        $date = $this->user->getCarbonFactory()
            ->today()
            ->subDay()
            ->setTimezone(config('app.timezone'));
        $data = [
            'invoice_uuid' => $invoice->uuid,
            'payment_method_id' => null,
            'invoice_payment_term_uuid' => $term->uuid,
            'paid_at' => $this->getDateForInvoice($date),
            'amount' => (int) round($invoice->amount_due / 2),
            'made_by' => null,
        ];

        $this->post(route('payments.store'), $data)
            ->assertSessionHas('success')
            ->assertRedirect();

        $invoice->refresh();
        $this->assertEquals(1, $invoice->invoicePayments()->count());
        $this->assertDatabaseHas('invoice_payments', [
            'tenant_id' => $this->tenant->id,
            'school_id' => $this->school->id,
            'invoice_uuid' => $invoice->uuid,
            'amount' => $data['amount'],
            'paid_at' => $date->toDateTimeString(),
            'invoice_payment_term_uuid' => $term->uuid,
        ]);

        // The "amount due" that will be referenced is the schedule's amount due, not the invoice's
        $this->assertEquals($term->invoicePaymentSchedule->amount - $data['amount'], $invoice->remaining_balance);
        $this->assertEquals($term->invoice_payment_schedule_uuid, $invoice->invoice_payment_schedule_uuid);
    }

    public function test_cant_save_payment_with_invalid_amount()
    {
        $this->assignPermission('create', InvoicePayment::class);

        $invoice = $this->createInvoice();
        $date = $this->user->getCarbonFactory()
            ->today()
            ->subDay()
            ->setTimezone(config('app.timezone'));
        $data = [
            'invoice_uuid' => $invoice->uuid,
            'payment_method_id' => null,
            'paid_at' => $this->getDateForInvoice($date),
            'amount' => $invoice->amount_due + 10,
            'made_by' => null,
        ];

        $this->post(route('payments.store'), $data)
            ->assertSessionHasErrors(['amount']);
    }

    public function test_can_distribute_payment_across_terms_correctly()
    {
        Queue::fake();
        $invoice = $this->createInvoice()->refresh();
        $this->seedPaymentSchedules($invoice, 2);
        $paymentAmount = (int) round($invoice->amount_due / 3);
        $payments = $invoice->invoicePayments()
            ->saveMany(
                InvoicePayment::factory()
                    ->count(2)
                    ->make([
                        'amount' => $paymentAmount,
                        'recorded_by' => $this->user->uuid,
                    ])
            );
        $totalPaid = $paymentAmount * $payments->count();

        $invoice->distributePaymentsToTerms()
            ->setRemainingBalance()
            ->save();

        $invoice->student->setRevenue();

        $this->assertEquals($totalPaid, $invoice->total_paid);
        $this->assertEquals($totalPaid, $invoice->student->revenue);

        foreach ($invoice->invoicePaymentSchedules as $schedule) {
            $paidToSchedule = $schedule->invoicePaymentTerms
                ->reduce(fn (int $total, InvoicePaymentTerm $term) => $total + $term->amount_due - $term->remaining_balance, 0);

            $this->assertEquals($totalPaid, $paidToSchedule);
        }
    }

    public function test_can_add_payment_to_child_invoice()
    {
        Queue::fake();
        $this->assignPermission('create', InvoicePayment::class);

        $parent = $this->createCombinedInvoice(2);
        /** @var Invoice $child */
        $child = $parent->children->random();
        $amount = (int) round($child->amount_due / 2);

        $this->assertGreaterThan(0, $amount);

        $date = $this->user->getCarbonFactory()
            ->today()
            ->subDay()
            ->setTimezone(config('app.timezone'));
        $data = [
            'invoice_uuid' => $child->uuid,
            'payment_method_id' => null,
            'invoice_payment_term_uuid' => null,
            'paid_at' => $this->getDateForInvoice($date),
            'amount' => (int) round($child->amount_due / 2),
            'made_by' => null,
        ];

        $this->post(route('payments.store'), $data)
            ->assertSessionHas('success')
            ->assertRedirect();

        $child->refresh();
        $parent->refresh();
        $this->assertEquals($child->amount_due - $data['amount'], $child->remaining_balance);
        $this->assertEquals($parent->amount_due - $data['amount'], $parent->remaining_balance);
        $this->assertEquals($data['amount'], $child->total_paid);
        $this->assertEquals($data['amount'], $parent->total_paid);

        $this->assertTrue(
            $child->activities->some(fn ($a) => Str::contains($a->description, 'recorded a payment'))
        );
        $this->assertTrue(
            $parent->activities->some(fn ($a) => Str::contains($a->description, "made to {$child->invoice_number}"))
        );
        Queue::assertPushed(SetStudentCachedValues::class, function ($job) use ($child) {
            return $job->studentUuid === $child->student_uuid;
        });
    }

    public function test_can_make_simple_payment_to_combined_invoice()
    {
        $this->assignPermission('create', InvoicePayment::class);

        $invoice = $this->createCombinedInvoice(5);
        $invoice->unsetRelations();

        $date = $this->user->getCarbonFactory()
            ->today()
            ->subDay()
            ->setTimezone(config('app.timezone'));
        $childrenCount = $invoice->children()->count();
        $basePayment = $invoice->amount_due / $childrenCount;

        $data = [
            'invoice_uuid' => $invoice->uuid,
            'payment_method_id' => null,
            'invoice_payment_term_uuid' => null,
            'paid_at' => $this->getDateForInvoice($date),
            'amount' => $basePayment % $childrenCount === 0
                ? (int) $basePayment + 1
                : (int) floor($basePayment),
            'made_by' => null,
        ];

        $this->post(route('payments.store'), $data)
            ->assertSessionHas('success')
            ->assertRedirect();

        $invoice->refresh();
        /** @var InvoicePayment $payment */
        $payment = $invoice->invoicePayments->first();

        $this->assertEquals($invoice->amount_due - $data['amount'], $invoice->children()->sum('remaining_balance'));
        $this->assertEquals($invoice->remaining_balance, $payment->remaining_balance);

        // Assert that the terms got distributed payments correctly
        foreach ($invoice->invoicePaymentSchedules as $schedule) {
            $distributedToTerms = $schedule->invoicePaymentTerms->reduce(function (int $total, InvoicePaymentTerm $term) {
                return $total + ($term->amount_due - $term->remaining_balance);
            }, 0);

            $this->assertEquals($data['amount'], $distributedToTerms);
        }

        // Each child invoice will have received a distribution of some kind
        foreach ($invoice->children as $child) {
            $this->assertEquals($child->student->invoices()->sum('remaining_balance'), $child->student->account_balance);

            if ($child->amount_due > 0) {
                // This just makes sure that the payment was recorded
                $this->assertTrue($child->amount_due > $child->remaining_balance);
                $this->assertEquals(1, $child->invoicePayments()->count());
            } else {
                $this->assertEquals(0, $child->invoicePayments()->count());
            }
        }

        $this->assertEquals(1, $payment->receipts()->count());

        /** @var Receipt $receipt */
        $receipt = $payment->receipts()->first();

        $this->disk->assertExists($receipt->path);
    }

    public function test_can_make_full_payment_to_combined_invoice()
    {
        Queue::fake();
        $this->assignPermission('create', InvoicePayment::class);

        $invoice = $this->createCombinedInvoice(5);
        $this->assertEquals($invoice->amount_due, $invoice->remaining_balance);

        $date = $this->user->getCarbonFactory()
            ->today()
            ->subDay()
            ->setTimezone(config('app.timezone'));
        $data = [
            'invoice_uuid' => $invoice->uuid,
            'payment_method_id' => null,
            'invoice_payment_term_uuid' => null,
            'paid_at' => $this->getDateForInvoice($date),
            'amount' => $invoice->amount_due,
            'made_by' => null,
        ];

        $this->post(route('payments.store'), $data)
            ->assertSessionHas('success')
            ->assertRedirect();

        $invoice->refresh();
        $this->assertEquals($invoice->amount_due - $data['amount'], $invoice->children()->sum('remaining_balance'));
        $this->assertEquals(0, $invoice->invoicePayments->first()->remaining_balance);

        // Assert that the terms got distributed payments correctly
        foreach ($invoice->invoicePaymentSchedules as $schedule) {
            $distributedToTerms = $schedule->invoicePaymentTerms->reduce(function (int $total, InvoicePaymentTerm $term) {
                return $total + max($term->amount_due - $term->remaining_balance, 0);
            }, 0);

            $this->assertEquals($data['amount'], $distributedToTerms);
        }

        Queue::assertPushed(SetStudentCachedValues::class, function ($job, $something, $else) use ($invoice) {
            return $invoice->children->pluck('student_uuid')->contains($job->studentUuid);
        });

        // Each child invoice will have received a distribution of some kind
        foreach ($invoice->children as $child) {
            $this->assertEquals(0, $child->remaining_balance);
            $this->assertEquals($child->amount_due > 0 ? 1 : 0, $child->invoicePayments()->count());
        }

        Queue::assertPushed(MakeReceipt::class);
    }

    public function test_can_fetch_payments_for_invoice()
    {
        Queue::fake();
        $this->assignPermission('view', InvoicePayment::class);

        $invoice = $this->createInvoice();
        $this->createPayment(invoice: $invoice);
        $this->createPayment(invoice: $invoice);

        $this->get(route('invoices.payments', $invoice))
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_can_fetch_related_payments()
    {
        Queue::fake();
        $this->assignPermission('view', InvoicePayment::class);

        $invoice = $this->createCombinedInvoice();
        $this->createPayment(invoice: $invoice);
        $this->createPayment(invoice: $invoice);
        $child = $invoice->children->random();
        $this->createPayment(invoice: $child);

        $json = $this->get(route('invoices.payments.related', $invoice))
            ->assertOk()
            ->assertJsonCount(1)
            ->json();

        $payment = Arr::first($json);
        $this->assertEquals($payment['invoice']['uuid'], $child->uuid);
    }

    public function test_can_get_edit_payment_page()
    {
        Queue::fake();
        $this->assignPermission('update', InvoicePayment::class);

        $payment = $this->createPayment();

        $this->get(route('payments.edit', $payment))
            ->assertOk()
            ->assertViewHas('title')
            ->assertInertia(fn (Assert $page) => $page
                ->has('title')
                ->has('invoice')
                ->has('paidBy')
                ->has('payment')
                ->where('method', 'put')
                ->where('endpoint', route('payments.update', $payment))
                ->where('breadcrumbs', fn ($prop) => count($prop) > 1)
                ->component('payments/Create')
            );
    }

    public function test_can_update_existing_payment()
    {
        Queue::fake();
        $this->assignPermission('update', InvoicePayment::class);
        $invoice = $this->createInvoice(paymentSchedules: 1);
        $payment = $this->createPayment(invoice: $invoice);

        $date = $this->user->getCarbonFactory()
            ->today()
            ->subDays(rand(0, 10))
            ->setTimezone(config('app.timezone'));
        $amount = rand(1, $invoice->amount_due);

        $data = [
            'payment_method_id' => PaymentMethod::factory()->create()->id,
            'invoice_payment_term_uuid' => $invoice->invoicePaymentTerms->random()->uuid,
            'paid_at' => $this->getDateForInvoice($date),
            'amount' => $amount,
            'made_by' => $this->createUser()->uuid,
        ];

        $this->put(route('payments.update', $payment), $data)
            ->assertRedirect(route('invoices.show', $payment->invoice_uuid))
            ->assertSessionHas('success');

        $data['uuid'] = $payment->uuid;
        $data['invoice_uuid'] = $payment->invoice_uuid;
        $this->assertDatabaseHas($payment->getTable(), $data);
        $this->assertEquals(1, $payment->activities()->count());

        $invoice = Invoice::find($payment->invoice_uuid)
            ->setCalculatedAttributes();
        $activities = $invoice->activities()
            ->get();

        $this->assertTrue(
            $activities
                ->some(fn ($activity) => Str::contains($activity->description, 'updated an existing payment, including the amount from'))
        );
        $this->assertEquals($invoice->amount_due - $amount, $invoice->remaining_balance);
    }
}
