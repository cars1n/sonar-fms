<?php

namespace App\Http\Controllers\Api;

use App\Models\Invoice;
use App\Models\School;
use App\Services\PortalPublisher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortalInvoiceV2Controller extends PortalInvoiceController
{
    protected function validatedContext(Request $request, bool $includeFilters = false): array
    {
        $data = app(\App\Http\Requests\PortalInvoiceContextRequest::class)->validated();

        return $data + ['student_sis_ids' => $data['student_refs']];
    }

    protected function studentUuids(School $school, array $references): array
    {
        $students = $school->students()->whereIn('crm_reference', $references)->pluck('uuid')->all();
        abort_if(count($students) !== count($references), 404);

        return $students;
    }

    protected function visibleInvoices(School $school, array $studentUuids): Builder
    {
        return parent::visibleInvoices($school, $studentUuids)
            ->where('published_at', '<=', now())
            ->whereIn('uuid', request()->input('invoice_uuids', []));
    }

    public function download(Request $request, string $invoice): StreamedResponse
    {
        $data = $this->validatedContext($request);
        $school = $this->school($data['school_sis_id']);
        $students = $this->studentUuids($school, $data['student_sis_ids']);
        $model = $this->visibleInvoices($school, $students)->where('uuid', $invoice)->firstOrFail();

        return \Illuminate\Support\Facades\DB::transaction(function () use ($model, $school, $students): StreamedResponse {
            $model = $this->visibleInvoices($school, $students)->whereKey($model->uuid)->lockForUpdate()->firstOrFail();
            $revision = app(PortalPublisher::class)->snapshot($model)['revision'];
            $pdf = $model->invoicePdfs()->where('portal_revision', $revision)->latest()->first();
            if (! $pdf || ! Invoice::getPdfDisk()->exists($pdf->relative_path)) {
                $pdf = $model->savePdf();
                $pdf->forceFill(['portal_revision' => $revision])->save();
            }

            abort_unless(app(PortalPublisher::class)->snapshot($model)['revision'] === $revision, 409, 'The invoice changed during PDF generation. Please retry.');

            return Invoice::getPdfDisk()->download($pdf->relative_path, $model->invoice_number.'.pdf', ['X-Invoice-Revision' => $revision]);
        });
    }
}
