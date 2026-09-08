<?php

namespace App\Jobs;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNewInvoiceNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $invoiceUuid;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(string $invoiceUuid)
    {
        $this->invoiceUuid = $invoiceUuid;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $invoice = Invoice::findOrFail($this->invoiceUuid);

        app(\App\Services\PortalPublisher::class)->capture($invoice);
    }
}
