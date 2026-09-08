<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PortalInvoiceResource;
use App\Models\Invoice;
use App\Models\School;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortalInvoiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $this->validatedContext($request, true);
        $school = $this->school($validated['school_sis_id']);
        $studentUuids = $this->studentUuids($school, $validated['student_sis_ids']);
        $query = $this->visibleInvoices($school, $studentUuids);

        $query->when($validated['search'] ?? null, function (Builder $query, string $search): void {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('title', 'ilike', "%{$search}%")
                    ->orWhere('invoice_number', 'ilike', "%{$search}%")
                    ->orWhereHas('student', fn (Builder $query) => $query->search($search))
                    ->orWhereHas('students', fn (Builder $query) => $query->search($search));
            });
        });

        $this->applyStatus($query, $validated['status'] ?? 'all');

        $summaryQuery = clone $query;
        $summary = [
            'invoice_count' => (clone $summaryQuery)->count(),
            'outstanding_count' => (clone $summaryQuery)->whereNull('paid_at')->whereNull('voided_at')->whereNull('canceled_at')->count(),
            'past_due_count' => (clone $summaryQuery)->whereNull('paid_at')->whereNull('voided_at')->whereNull('canceled_at')->where('due_at', '<', now())->count(),
            'remaining_balance' => (int) (clone $summaryQuery)->whereNull('voided_at')->whereNull('canceled_at')->sum('remaining_balance'),
        ];
        $summary['remaining_balance_formatted'] = displayCurrency($summary['remaining_balance'], $school->currency);

        $invoices = $query
            ->with($this->relationships())
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return PortalInvoiceResource::collection($invoices)
            ->additional(['summary' => $summary]);
    }

    public function show(Request $request, string $invoice): PortalInvoiceResource
    {
        $validated = $this->validatedContext($request);
        $school = $this->school($validated['school_sis_id']);
        $studentUuids = $this->studentUuids($school, $validated['student_sis_ids']);

        $invoice = $this->visibleInvoices($school, $studentUuids)
            ->with($this->relationships())
            ->where('uuid', $invoice)
            ->firstOrFail();

        return new PortalInvoiceResource($invoice);
    }

    public function download(Request $request, string $invoice): StreamedResponse
    {
        $validated = $this->validatedContext($request);
        $school = $this->school($validated['school_sis_id']);
        $studentUuids = $this->studentUuids($school, $validated['student_sis_ids']);

        $invoice = $this->visibleInvoices($school, $studentUuids)
            ->where('uuid', $invoice)
            ->firstOrFail();
        $pdf = $invoice->latestPdf();

        return Invoice::getPdfDisk()
            ->download($pdf->relative_path, $invoice->invoice_number.'.pdf');
    }

    protected function validatedContext(Request $request, bool $includeFilters = false): array
    {
        $rules = [
            'school_sis_id' => ['required', 'integer'],
            'student_sis_ids' => ['required', 'array', 'min:1', 'max:50'],
            'student_sis_ids.*' => ['required', 'integer', 'distinct'],
        ];

        if ($includeFilters) {
            $rules += [
                'search' => ['nullable', 'string', 'max:100'],
                'status' => ['nullable', Rule::in(['all', 'outstanding', 'paid', 'past_due'])],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ];
        }

        return $request->validate($rules);
    }

    protected function school(int $schoolSisId): School
    {
        return School::query()
            ->with('currency')
            ->where('sis_id', $schoolSisId)
            ->firstOrFail();
    }

    protected function studentUuids(School $school, array $studentSisIds): array
    {
        $studentUuids = Student::query()
            ->where('school_id', $school->id)
            ->whereIn('sis_id', $studentSisIds)
            ->pluck('uuid')
            ->all();

        abort_if(count($studentUuids) !== count($studentSisIds), 404);

        return $studentUuids;
    }

    protected function visibleInvoices(School $school, array $studentUuids): Builder
    {
        return Invoice::query()
            ->where('school_id', $school->id)
            ->whereNull('parent_uuid')
            ->published()
            ->where(function (Builder $query): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            })
            ->where(function (Builder $query) use ($studentUuids): void {
                $query->whereIn('student_uuid', $studentUuids)
                    ->orWhere(function (Builder $query) use ($studentUuids): void {
                        $query->where('is_parent', true)
                            ->whereHas('children', fn (Builder $query) => $query->whereIn('student_uuid', $studentUuids))
                            ->whereDoesntHave('children', function (Builder $query) use ($studentUuids): void {
                                $query->whereNull('student_uuid')
                                    ->orWhereNotIn('student_uuid', $studentUuids);
                            });
                    });
            });
    }

    protected function applyStatus(Builder $query, string $status): void
    {
        if ($status === 'outstanding') {
            $query->whereNull('paid_at')->whereNull('voided_at')->whereNull('canceled_at');
        }

        if ($status === 'paid') {
            $query->whereNotNull('paid_at');
        }

        if ($status === 'past_due') {
            $query->whereNull('paid_at')
                ->whereNull('voided_at')
                ->whereNull('canceled_at')
                ->where('due_at', '<', now());
        }
    }

    protected function relationships(): array
    {
        return [
            'student',
            'students',
            'currency',
            'invoiceItems',
            'invoiceScholarships',
            'invoicePaymentSchedules.invoicePaymentTerms',
            'invoicePayments',
            'children.student',
            'children.students',
            'children.currency',
            'children.invoiceItems',
            'children.invoiceScholarships',
            'children.invoicePaymentSchedules.invoicePaymentTerms',
            'children.invoicePayments',
        ];
    }
}
