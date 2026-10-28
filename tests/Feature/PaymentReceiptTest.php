<?php

namespace Tests\Feature;

use App\Models\InvoicePayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\CreatesPayments;

class PaymentReceiptTest extends TestCase
{
    use CreatesPayments;
    use RefreshDatabase;

    protected bool $signIn = true;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_can_view_payment_receipt()
    {
        $this->assignPermission('view', InvoicePayment::class);
        $payment = $this->createPayment();

        $this->get("/payments/{$payment->id}/receipt")
            ->assertOk()
            ->assertViewIs('receipt')
            ->assertViewHas('title')
            ->assertViewHas('payment', fn (InvoicePayment $rendered) => $rendered->is($payment));
    }
}
