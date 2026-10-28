<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasResource;
use App\Traits\HasTaxRateAttribute;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @mixin IdeHelperSchool
 */
class School extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasResource;
    use HasTaxRateAttribute;

    protected $guarded = [];

    protected ?Term $currentTerm = null;

    protected $casts = [
        'active_at' => 'datetime',
        'collect_tax' => 'boolean',
        'include_draft_stamp' => 'boolean',
        'tax_rate' => 'float',
    ];

    public function scopeActive(Builder $builder)
    {
        $builder->where('active', true);
    }

    public function scopeInactive(Builder $builder)
    {
        $builder->where('active', false);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['staff_id']);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(Term::class);
    }

    public function fees(): HasMany
    {
        return $this->hasMany(Fee::class);
    }

    public function scholarships(): HasMany
    {
        return $this->hasMany(Scholarship::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function invoiceTemplates(): HasMany
    {
        return $this->hasMany(InvoiceTemplate::class);
    }

    public function invoiceImports(): HasMany
    {
        return $this->hasMany(InvoiceImport::class);
    }

    public function invoiceLayouts(): HasMany
    {
        return $this->hasMany(InvoiceLayout::class);
    }

    public function receiptLayouts(): HasMany
    {
        return $this->hasMany(ReceiptLayout::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class)
            ->orderBy('driver');
    }

    public function invoicePayments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function paymentImports(): HasMany
    {
        return $this->hasMany(PaymentImport::class);
    }

    public function paymentImportTemplates(): HasMany
    {
        return $this->hasMany(PaymentImportTemplate::class);
    }

    public function families(): HasMany
    {
        return $this->hasMany(Family::class);
    }

    public function getCurrentTerm(?Carbon $date = null): Term
    {
        if ($this->currentTerm) {
            return $this->currentTerm;
        }

        $date = $date ?? now();

        return $this->currentTerm = $this->terms()
            ->where('starts_at', '<=', $date)
            ->where('ends_at', '>=', $date)
            ->orderBy('portion', 'desc')
            ->first() ?? Term::makeFromNow();
    }

    public function compileTemplate(string $subject, ?User $user = null, ?Student $student = null, ?Term $term = null): string
    {
        $subject = trim($subject);

        if (! $subject) {
            return '';
        }

        $user = $user ?? auth()->user();
        $now = $user
            ? $user->getCarbonFactory()->now()
            : now();
        $term = $term ?? $this->getCurrentTerm($now);
        $student = $student ?? new Student;

        $search = [
            '{year}',
            '{month}',
            '{day}',
            '{term}',
            '{school_year}',
            '{next_school_year}',
            '{student_number}',
            '{sis_id}',
            '{first_name}',
            '{last_name}',
        ];
        $replace = [
            $now->format('Y'),
            $now->format('m'),
            $now->format('d'),
            $term->abbreviation ?? '',
            $term->school_years ?? '',
            $term->next_school_years ?? '',
            $student->student_number ?? '',
            $student->sis_id ?? '',
            $student->first_name ?? '',
            $student->last_name ?? '',
        ];

        return Str::replace($search, $replace, $subject);
    }

    public function getInvoiceNumberPrefix(?User $user = null, ?Student $student = null): string
    {
        return strtoupper($this->compileTemplate($this->invoice_number_template ?? '', $user, $student));
    }

    public function getGradeLevelsAttribute(): array
    {
        return range($this->low_grade, $this->high_grade);
    }

    public function getDefaultInvoiceLayout(): InvoiceLayout
    {
        $layout = $this->invoiceLayouts()
            ->default()
            ->first();

        return $layout ?? InvoiceLayout::makeDefault();
    }

    public function getDefaultReceiptLayout(): ReceiptLayout
    {
        $layout = $this->receiptLayouts()
            ->default()
            ->first();

        return $layout ?? ReceiptLayout::makeDefault();
    }

    public function syncDataFromSis()
    {
        $this->tenant->sisProvider()->fullSchoolSync($this);
    }

    public static function current(): ?School
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user?->school;
    }

    public function getPaymentMethods(): array
    {
        return PaymentMethod::getListForSchool($this);
    }
}
