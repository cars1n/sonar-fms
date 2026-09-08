<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PortalEvent;
use App\Models\PortalInvoiceState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PortalPublisher
{
    public function enabledForSchool(\App\Models\School $school): bool
    {
        return config('services.crm_portal.enabled') && $this->configuredForSchool($school);
    }

    public function configuredForSchool(\App\Models\School $school): bool
    {
        return $school->tenant_id === config('services.crm_portal.tenant_id')
            && in_array((int) $school->sis_id, config('services.crm_portal.school_sis_ids', []), true);
    }

    public function snapshot(Invoice $invoice): array
    {
        $invoice = $invoice->fresh();
        $invoice->load(['school', 'student', 'children.student', 'currency', 'invoiceItems', 'invoiceScholarships', 'invoicePaymentSchedules.invoicePaymentTerms', 'invoicePayments',
            'children.currency', 'children.invoiceItems', 'children.invoiceScholarships', 'children.invoicePaymentSchedules.invoicePaymentTerms', 'children.invoicePayments', 'students', 'children.students']);
        $data = (new \App\Http\Resources\PortalInvoiceResource($invoice))->resolve();
        $revision = hash('sha256', json_encode([
            'portal' => $data,
            'invoice' => $invoice->getRawOriginal(),
            'children' => $invoice->children->map->getRawOriginal()->all(),
            'layout' => $invoice->school->getDefaultInvoiceLayout()->getAttributes(),
            'payment_methods' => $invoice->school->paymentMethods()->showOnInvoice()->orderBy('id')->get()->map->getRawOriginal()->all(),
        ], JSON_THROW_ON_ERROR));
        $students = $invoice->is_parent ? $invoice->children->map->student : collect([$invoice->student]);
        $students = $students->unique(fn ($student) => $student?->uuid)->values();
        $visible = $invoice->published_at && $invoice->published_at <= now()
            && (! $invoice->available_at || $invoice->available_at <= now()) && ! $invoice->parent_uuid;

        return [
            'invoice_uuid' => $invoice->uuid,
            'school_sis_id' => (int) $invoice->school->sis_id,
            'student_refs' => $students->filter()->pluck('crm_reference')->values()->all(),
            'student_sis_ids' => $students->filter()->pluck('sis_id')->values()->all(),
            'student_count' => $students->count(),
            'visible' => (bool) $visible,
            'published_at' => $invoice->published_at?->toIso8601String(),
            'available_at' => $invoice->available_at?->toIso8601String(),
            'notify' => (bool) $invoice->notify,
            'canceled' => (bool) ($invoice->voided_at || $invoice->canceled_at),
            'revision' => $revision,
            'invoice' => $data,
        ];
    }

    public function capture(Invoice $invoice, bool $silent = false, bool $resend = false): ?PortalEvent
    {
        if (! $this->enabledForSchool($invoice->school) && ! ($silent && $this->configuredForSchool($invoice->school))) {
            return null;
        }

        return DB::transaction(function () use ($invoice, $silent, $resend): ?PortalEvent {
            $invoice = Invoice::whereKey($invoice->uuid)->lockForUpdate()->firstOrFail();
            $state = PortalInvoiceState::firstOrCreate(['invoice_uuid' => $invoice->uuid], [
                'tenant_id' => $invoice->tenant_id, 'school_id' => $invoice->school_id,
            ]);
            $data = $this->snapshot($invoice);
            unset($data['invoice']);
            if (! $silent && $data['notify'] && $invoice->published_at && (! $state->was_published || $resend)) {
                $state->request_id = (string) Str::uuid();
                $state->send_at = collect([
                    $invoice->published_at->copy()->addMinutes(15),
                    $invoice->available_at, $invoice->notify_at,
                ])->filter()->sort()->last();
            }
            if ($silent || ! $data['notify'] || $data['canceled'] || ! $invoice->published_at) {
                $state->request_id = null;
                $state->send_at = null;
            }
            if ($state->request_id) {
                $state->send_at = collect([$invoice->published_at->copy()->addMinutes(15), $invoice->available_at, $invoice->notify_at])->filter()->sort()->last();
            }
            $state->was_published = (bool) $invoice->published_at;
            $data['request_id'] = $state->request_id;
            $data['send_at'] = $state->send_at?->toIso8601String();
            $data['silent'] = $silent;
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if ($state->fingerprint === $hash) {
                return null;
            }
            $state->fingerprint = $hash;
            $state->save();

            return PortalEvent::create([
                'tenant_id' => $invoice->tenant_id, 'school_id' => $invoice->school_id,
                'payload' => $data,
            ]);
        });
    }

    public function deliver(PortalEvent $event): void
    {
        $url = config('services.crm_portal.webhook_url');
        $secret = config('services.crm_portal.webhook_secret');
        if (! $url || ! $secret) {
            return;
        }
        $body = json_encode(['id' => $event->id, 'data' => $event->payload], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        try {
            Http::connectTimeout(3)->timeout(15)->withBody($body, 'application/json')
                ->withHeaders(['X-Sonar-Timestamp' => $timestamp, 'X-Sonar-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret)])
                ->post($url)->throw();
            $event->update(['delivered_at' => now(), 'last_error' => null]);
        } catch (\Throwable $exception) {
            $event->update([
                'attempts' => $event->attempts + 1,
                'retry_at' => now()->addSeconds(min(3600, 30 * (2 ** min($event->attempts, 7)))),
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
        }
    }
}
