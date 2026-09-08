<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\PortalEvent;
use App\Models\Tenant;
use App\Services\PortalPublisher;
use Illuminate\Console\Command;

class SyncPortal extends Command
{
    protected $signature = 'portal:sync {--backfill : Record existing invoices without requesting email} {--full : Reconcile all invoices}';

    protected $description = 'Capture invoice changes and retry durable CRM webhook deliveries';

    public function handle(PortalPublisher $publisher): int
    {
        if (! config('services.crm_portal.enabled') && ! $this->option('backfill')) {
            return self::SUCCESS;
        }
        Tenant::all()->each(function (Tenant $tenant) use ($publisher): void {
            $tenant->execute(function () use ($publisher): void {
                Invoice::query()->when(! $this->option('backfill') && ! $this->option('full'), function ($query): void {
                    $query->where(function ($query): void {
                        $query->where('updated_at', '>=', now()->subMinutes(10))
                            ->orWhereBetween('available_at', [now()->subMinutes(10), now()])
                            ->orWhereNotIn('uuid', \App\Models\PortalInvoiceState::select('invoice_uuid'));
                    });
                })->orderBy('uuid')->chunk(100, function ($invoices) use ($publisher): void {
                    foreach ($invoices as $invoice) {
                        $publisher->capture($invoice, (bool) $this->option('backfill'));
                    }
                });
                if (! $this->option('backfill')) {
                    PortalEvent::whereNull('delivered_at')->where(fn ($q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', now()))
                        ->orderBy('id')->limit(100)->get()->each(fn ($event) => $publisher->deliver($event));
                }
            });
        });

        return self::SUCCESS;
    }
}
