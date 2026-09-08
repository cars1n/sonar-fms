<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Http\Resources\Json\JsonResource;

class PortalInvoiceResource extends JsonResource
{
    public static $wrap = 'data';

    public function toArray($request): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->resource;

        return [
            'uuid' => $invoice->uuid,
            'invoice_number' => $invoice->invoice_number,
            'title' => $invoice->title,
            'description' => trim(strip_tags((string) $invoice->description)),
            'status' => $this->status($invoice),
            'status_label' => $invoice->status_label,
            'is_void' => $invoice->is_void,
            'is_parent' => $invoice->is_parent,
            'invoice_date' => optional($invoice->invoice_date)->toDateString(),
            'due_at' => optional($invoice->due_at)->toIso8601String(),
            'paid_at' => optional($invoice->paid_at)->toIso8601String(),
            'amount_due' => $invoice->amount_due,
            'amount_due_formatted' => displayCurrency($invoice->amount_due, $invoice->currency),
            'remaining_balance' => $invoice->remaining_balance,
            'remaining_balance_formatted' => displayCurrency($invoice->remaining_balance, $invoice->currency),
            'total_paid' => $invoice->total_paid,
            'total_paid_formatted' => displayCurrency($invoice->total_paid, $invoice->currency),
            'tax_due' => $invoice->tax_due,
            'tax_due_formatted' => displayCurrency($invoice->tax_due, $invoice->currency),
            'student' => $invoice->relationLoaded('student') && $invoice->student
                ? $this->student($invoice->student)
                : null,
            'students' => $invoice->relationLoaded('students')
                ? $invoice->students->map(fn (Student $student) => $this->student($student))->values()
                : [],
            'items' => $invoice->relationLoaded('invoiceItems')
                ? $invoice->invoiceItems->map(fn ($item) => [
                    'uuid' => $item->uuid,
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'amount_per_unit' => $item->amount_per_unit,
                    'amount_per_unit_formatted' => displayCurrency($item->amount_per_unit, $invoice->currency),
                    'amount' => $item->amount,
                    'amount_formatted' => displayCurrency($item->amount, $invoice->currency),
                ])->values()
                : [],
            'scholarships' => $invoice->relationLoaded('invoiceScholarships')
                ? $invoice->invoiceScholarships->map(fn ($scholarship) => [
                    'uuid' => $scholarship->uuid,
                    'name' => $scholarship->name,
                    'amount' => $scholarship->calculated_amount,
                    'amount_formatted' => displayCurrency($scholarship->calculated_amount, $invoice->currency),
                ])->values()
                : [],
            'payment_schedules' => $invoice->relationLoaded('invoicePaymentSchedules')
                ? $invoice->invoicePaymentSchedules->map(fn ($schedule) => [
                    'uuid' => $schedule->uuid,
                    'amount' => $schedule->amount,
                    'amount_formatted' => displayCurrency($schedule->amount, $invoice->currency),
                    'terms' => $schedule->invoicePaymentTerms->map(fn ($term) => [
                        'uuid' => $term->uuid,
                        'amount_due' => $term->amount_due,
                        'amount_due_formatted' => displayCurrency($term->amount_due, $invoice->currency),
                        'remaining_balance' => $term->remaining_balance,
                        'remaining_balance_formatted' => displayCurrency($term->remaining_balance, $invoice->currency),
                        'due_at' => optional($term->due_at)->toIso8601String(),
                    ])->values(),
                ])->values()
                : [],
            'payments' => $invoice->relationLoaded('invoicePayments')
                ? $invoice->invoicePayments->map(fn ($payment) => [
                    'uuid' => $payment->uuid,
                    'amount' => $payment->amount,
                    'amount_formatted' => displayCurrency($payment->amount, $invoice->currency),
                    'paid_at' => optional($payment->paid_at)->toIso8601String(),
                ])->values()
                : [],
            'children' => $invoice->relationLoaded('children')
                ? static::collection($invoice->children)
                : [],
        ];
    }

    protected function student(Student $student): array
    {
        return [
            'sis_id' => (string) $student->sis_id,
            'student_number' => (string) $student->student_number,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'full_name' => $student->full_name,
            'grade_level' => $student->grade_level,
        ];
    }

    protected function status(Invoice $invoice): string
    {
        if ($invoice->voided_at) {
            return 'void';
        }

        if ($invoice->canceled_at) {
            return 'canceled';
        }

        if ($invoice->paid_at) {
            return 'paid';
        }

        if ($invoice->payment_made) {
            return 'partial';
        }

        if ($invoice->past_due) {
            return 'past_due';
        }

        return 'unpaid';
    }
}
