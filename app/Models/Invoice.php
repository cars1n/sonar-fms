<?php

namespace App\Models;

use App\Concerns\Exportable;
use App\Factories\PdfBrowserFactory;
use App\Factories\UuidFactory;
use App\Http\Requests\SaveRefundRequest;
use App\Jobs\CalculateInvoiceAttributes;
use App\Jobs\CreateInvoicePdf;
use App\Jobs\MakeReceipt;
use App\Jobs\SendNewInvoiceNotification;
use App\Jobs\SetStudentCachedValues;
use App\Services\PortalPublisher;
use App\Traits\BelongsToSchool;
use App\Traits\BelongsToTenant;
use App\Traits\BelongsToUser;
use App\Traits\HasActivities;
use App\Traits\HasGradeLevelAttribute;
use App\Traits\HasResource;
use App\Traits\HasTaxRateAttribute;
use App\Traits\UsesUuid;
use GrantHolle\Timezone\Facades\Timezone;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Searchable\Searchable;
use Spatie\Searchable\SearchResult;

/**
 * @mixin IdeHelperInvoice
 */
class Invoice extends Model implements Exportable, Searchable
{
    use BelongsToSchool;
    use BelongsToTenant;
    use BelongsToUser;
    use HasActivities;
    use HasFactory;
    use HasGradeLevelAttribute;
    use HasResource;
    use HasTaxRateAttribute;
    use UsesUuid;

    public const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    protected $fillable = [
        'uuid',
        'batch_id',
        'invoice_import_id',
        'tenant_id',
        'school_id',
        'student_uuid',
        'term_id',
        'invoice_layout_id',
        'title',
        'description',
        'amount_due',
        'remaining_balance',
        'invoice_date',
        'available_at',
        'due_at',
        'paid_at',
        'voided_at',
        'canceled_at',
        'notify',
        'notify_at',
        'notified_at',
        'apply_tax',
        'use_school_tax_defaults',
        'tax_rate',
        'tax_label',
        'tax_due',
        'pre_tax_subtotal',
        'parent_uuid',
        'published_at',
        'apply_tax_to_all_items',
        'relative_tax_rate',
        'invoice_number',
        'is_parent',
        'invoice_payment_schedule_uuid',
        'total_paid',
        'grade_level_adjustment',
        'grade_level',
    ];

    protected $casts = [
        'notify_now' => 'boolean',
        'apply_tax' => 'boolean',
        'use_school_tax_defaults' => 'boolean',
        'apply_tax_to_all_items' => 'boolean',
        'tax_rate' => 'float',
        'relative_tax_rate' => 'float',
        'invoice_date' => 'date',
        'due_at' => 'datetime',
        'voided_at' => 'datetime',
        'canceled_at' => 'datetime',
        'paid_at' => 'datetime',
        'notify_at' => 'datetime',
        'notified_at' => 'datetime',
        'available_at' => 'datetime',
        'published_at' => 'datetime',
        'is_parent' => 'boolean',
        'total_paid' => 'int',
        'amount_due' => 'int',
        'remaining_balance' => 'int',
        'grade_level_adjustment' => 'int',
        'grade_level' => 'int',
    ];

    // These are the attributes/properties that are
    // used on the invoice form based on the API Resource
    public static array $formAttributes = [
        'students',
        'title',
        'description',
        'term_id',
        'available_at',
        'due_at',
        'notify',
        'items',
        'scholarships',
        'payment_schedules',
        'apply_tax',
        'use_school_tax_defaults',
        'tax_rate',
        'tax_label',
    ];

    protected static function booted()
    {
        static::saved(function (Invoice $invoice) {
            if (config('services.crm_portal.enabled')) {
                DB::afterCommit(function () use ($invoice): void {
                    $current = Invoice::find($invoice->uuid);
                    if ($current) {
                        app(PortalPublisher::class)->capture($current);
                    }
                });
            }
            if ($invoice->isDirty('voided_at')) {
                // __('Invoice voided by :user.')
                activity()
                    ->on($invoice)
                    ->log('Invoice voided by :user.');
            }

            if ($invoice->isDirty('canceled_at')) {
                // __('Invoice canceled by :user.')
                activity()
                    ->on($invoice)
                    ->log('Invoice canceled by :user.');
            }

            if ($invoice->isDirty('published_at')) {
                // __('Invoice published by :user.')
                activity()
                    ->on($invoice)
                    ->log('Invoice published by :user.');
            }
        });

        static::updated(function (Invoice $invoice) {
            // If the invoice has been voided or the published time has changed
            // run the recalculations on the parent
            if ($invoice->isDirty('voided_at') || $invoice->isDirty('published_at') || $invoice->isDirty('canceled_at')) {
                if ($invoice->parent_uuid) {
                    dispatch(new CalculateInvoiceAttributes($invoice->parent_uuid));
                }

                // Update cached values
                if ($invoice->student_uuid) {
                    SetStudentCachedValues::dispatch($invoice->student_uuid);
                }

                dispatch(new CreateInvoicePdf($invoice->uuid));
            }
        });
    }

    public function scopeFilter(Builder $builder, array $filters)
    {
        $builder->when($filters['s'] ?? null, function (Builder $builder, $search) {
            $builder->search($search); // @phpstan-ignore-line
        })->when($filters['batch_id'] ?? null, function (Builder $builder, $batchId) {
            $builder->where('batch_id', $batchId);
        })->when($filters['ids'] ?? null, function (Builder $builder, $ids) {
            if (is_array($ids)) {
                $builder->whereIn('uuid', $ids);
            } else {
                $builder->where('uuid', $ids);
            }
        })->when($filters['status'] ?? [], function (Builder $builder, array $statuses) {
            if (empty($statuses)) {
                return;
            }

            $builder->where(function (Builder $builder) use ($statuses) {
                if (in_array('unpaid', $statuses)) {
                    $builder->orWhere(function (Builder $builder) {
                        $builder->whereNull('paid_at')
                            ->whereNull('voided_at');
                    });
                }
                if (in_array('paid', $statuses)) {
                    $builder->orWhereNotNull('paid_at');
                }
                if (in_array('partial', $statuses)) {
                    $builder->orWhere(function (Builder $builder) {
                        $builder->whereNull('paid_at')
                            ->where('total_paid', '>', 0);
                    });
                }
                if (in_array('published', $statuses)) {
                    $builder->orWhere(function (Builder $builder) {
                        $builder->whereNotNull('published_at')
                            ->whereNull('voided_at');
                    });
                }
                if (in_array('draft', $statuses)) {
                    $builder->orWhereNull('published_at');
                }
                if (in_array('past', $statuses)) {
                    $builder->orWhere(function (Builder $builder) {
                        $builder->where('due_at', '<', now())
                            ->whereNull('voided_at');
                    });
                }
                if (in_array('void', $statuses)) {
                    $builder->orWhereNotNull('voided_at');
                }
                if (in_array('canceled', $statuses)) {
                    $builder->orWhereNotNull('canceled_at');
                }
            });
        })->unless(in_array('void', $filters['status'] ?? []), function (Builder $builder) {
            $builder->whereNull('voided_at');
        })->when($filters['grades'] ?? null, function (Builder $builder, $grades) {
            $builder->where(function (Builder $builder) use ($grades) {
                $gradeQuery = function (Builder $builder) use ($grades) {
                    $builder->whereIn('grade_level', $grades);
                };

                $builder->whereHas('student', $gradeQuery)
                    ->orWhereHas('students', $gradeQuery);
            });
        })->when($filters['date_start'] ?? null, function (Builder $builder, $date) {
            $parsed = Timezone::toLocal(Carbon::parse($date), 'Y-m-d');

            $builder->where('invoice_date', '>=', $parsed);
        })->when($filters['date_end'] ?? null, function (Builder $builder, $date) {
            $parsed = Timezone::toLocal(Carbon::parse($date), 'Y-m-d');

            $builder->where('invoice_date', '<=', $parsed);
        })->when($filters['due_start'] ?? null, function (Builder $builder, $date) {
            $builder->where('due_at', '>=', $date);
        })->when($filters['due_end'] ?? null, function (Builder $builder, $date) {
            $builder->where('invoice_date', '<=', $date);
        })->when($filters['types'] ?? null, function (Builder $builder, array $types) {
            $builder->where(function (Builder $builder) use ($types) {
                if (in_array('combined', $types)) {
                    $builder->orWhere('is_parent', true);
                }

                if (in_array('individual', $types)) {
                    $builder->orWhere('is_parent', false);
                }
            });
        });

        $orderBy = $filters['orderBy'] ?? 'invoices.created_at';
        $orderDir = $filters['orderDir'] ?? 'desc';

        $builder->orderBy($orderBy, $orderDir);

        if ($orderBy !== 'title') {
            $builder->orderBy('invoices.title');
        }
    }

    public function scopeSearch(Builder $builder, string $search)
    {
        $builder->where(function (Builder $builder) use ($search) {
            $builder->where('invoices.uuid', 'ilike', "{$search}%")
                ->orWhere('title', 'ilike', "%{$search}%")
                ->orWhere('invoice_number', 'ilike', "%{$search}%")
                ->orWhereHas('student', function (Builder $builder) use ($search) {
                    $builder->search($search); // @phpstan-ignore-line
                })
                ->orWhereHas('students', function (Builder $builder) use ($search) {
                    $builder->search($search); // @phpstan-ignore-line
                });
        });
    }

    public function scopeForUser(Builder $builder, User $user)
    {
        $builder->leftJoin('invoice_user', function (JoinClause $join) {
            $join->on('invoices.uuid', '=', 'invoice_user.invoice_uuid');
        })
            ->where(function (Builder $builder) use ($user) {
                $builder->where('invoice_user.user_uuid', $user->uuid)
                    ->orWhereIn('student_uuid', $user->students->pluck('uuid'));
            });
    }

    public function scopeIsNotVoid(Builder $builder)
    {
        $builder->whereNull('voided_at');
    }

    public function scopeUnpaid(Builder $builder)
    {
        $builder->whereNull('paid_at');
    }

    public function scopePaid(Builder $builder)
    {
        $builder->whereNotNull('paid_at');
    }

    public function scopePaymentMade(Builder $builder)
    {
        $builder->where('invoices.amount_due', '!=', DB::raw('invoices.remaining_balance'));
    }

    public function scopeUnpublished(Builder $builder)
    {
        $builder->whereNull('published_at');
    }

    public function scopePublished(Builder $builder)
    {
        $builder->whereNotNull('published_at');
    }

    public function scopeNotAChild(Builder $builder)
    {
        $builder->whereNull('parent_uuid');
    }

    public function scopeBatch(Builder $builder, string $batch)
    {
        $builder->where('batch_id', $batch);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_uuid', 'uuid');
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_uuid', 'uuid');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_uuid', 'uuid');
    }

    public function invoiceScholarships(): HasMany
    {
        return $this->hasMany(InvoiceScholarship::class, 'invoice_uuid', 'uuid');
    }

    public function invoicePaymentSchedules(): HasMany
    {
        return $this->hasMany(InvoicePaymentSchedule::class, 'invoice_uuid', 'uuid');
    }

    public function invoicePaymentTerms(): HasMany
    {
        return $this->hasMany(InvoicePaymentTerm::class, 'invoice_uuid', 'uuid');
    }

    public function invoiceImport(): BelongsTo
    {
        return $this->belongsTo(InvoiceImport::class);
    }

    public function invoiceLayout(): BelongsTo
    {
        return $this->belongsTo(InvoiceLayout::class);
    }

    public function invoicePayments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class, 'invoice_uuid', 'uuid')
            ->orderBy('paid_at', 'desc');
    }

    public function invoiceTaxItems(): HasMany
    {
        return $this->hasMany(InvoiceTaxItem::class, 'invoice_uuid', 'uuid');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function invoicePaymentSchedule(): BelongsTo
    {
        return $this->belongsTo(InvoicePaymentSchedule::class, 'invoice_payment_schedule_uuid', 'uuid');
    }

    public function students(): HasManyThrough
    {
        return $this->hasManyThrough(
            Student::class,
            static::class,
            'parent_uuid',
            'uuid',
            'uuid',
            'student_uuid'
        )->distinct();
    }

    public function receipts(): HasManyThrough
    {
        return $this->hasManyThrough(
            Receipt::class,
            InvoicePayment::class,
            'invoice_uuid',
            'invoice_payment_uuid',
            'uuid',
            'uuid'
        );
    }

    public function invoicePdfs(): HasMany
    {
        return $this->hasMany(InvoicePdf::class);
    }

    public function invoiceRefunds(): HasMany
    {
        return $this->hasMany(InvoiceRefund::class);
    }

    public function hasLargerQuantities(): Attribute
    {
        return Attribute::get(function (): bool {
            if (! $this->relationLoaded('invoiceItems')) {
                return true;
            }

            return $this->invoiceItems->some(
                fn (InvoiceItem $item) => $item->quantity > 1
            );
        });
    }

    public function getIsVoidAttribute(): bool
    {
        return (bool) $this->voided_at;
    }

    public function getAmountDueFormattedAttribute(): ?string
    {
        if (! $this->relationLoaded('currency')) {
            return null;
        }

        return displayCurrency($this->amount_due, $this->currency);
    }

    public function getTaxDueFormattedAttribute(): ?string
    {
        if (! $this->relationLoaded('currency')) {
            return null;
        }

        return displayCurrency($this->tax_due, $this->currency);
    }

    public function getTotalPaidFormattedAttribute(): ?string
    {
        if (! $this->relationLoaded('currency')) {
            return null;
        }

        return displayCurrency($this->total_paid, $this->currency);
    }

    public function getSubtotalFormattedAttribute(): ?string
    {
        if (! $this->relationLoaded('currency')) {
            return null;
        }

        return displayCurrency($this->subtotal, $this->currency);
    }

    public function getDiscountTotalFormattedAttribute(): ?string
    {
        if (! $this->relationLoaded('currency')) {
            return null;
        }

        return displayCurrency($this->discount_total, $this->currency);
    }

    public function getRemainingBalanceFormattedAttribute(): ?string
    {
        if (! $this->relationLoaded('currency')) {
            return null;
        }

        return displayCurrency($this->remaining_balance, $this->currency);
    }

    public function getNumberFormattedAttribute(): string
    {
        return '#'.$this->id;
    }

    public function getStatusColorAttribute(): string
    {
        if (! $this->published_at) {
            return 'gray';
        }

        if ($this->paid_at) {
            return 'green';
        }

        if ($this->past_due || $this->voided_at || $this->canceled_at) {
            return 'red';
        }

        if ($this->payment_made || ! $this->available) {
            return 'yellow';
        }

        return 'yellow';
    }

    public function getStatusLabelAttribute(): string
    {
        if (! $this->published_at) {
            return __('Draft');
        }

        if ($this->paid_at) {
            return __('Paid');
        }

        if ($this->voided_at) {
            return __('Void');
        }

        if ($this->canceled_at) {
            return __('Canceled');
        }

        if ($this->payment_made) {
            return __('Partially paid');
        }

        if ($this->past_due) {
            return __('Past due');
        }

        if (! $this->available) {
            return __('Unavailable');
        }

        return __('Unpaid');
    }

    public function getIdAttribute($id): string
    {
        return $this->invoice_number;
    }

    public function getPaymentMadeAttribute(): bool
    {
        return $this->total_paid > 0;
    }

    public function getPastDueAttribute(): bool
    {
        return $this->due_at && now() > $this->due_at;
    }

    public function getAvailableAttribute(): bool
    {
        if (! $this->available_at) {
            return true;
        }

        return now() >= $this->available_at;
    }

    public function getStudentListAttribute(): string
    {
        if ($this->student_uuid && $this->relationLoaded('student')) {
            return $this->student->full_name;
        }

        if (! $this->student_uuid && $this->relationLoaded('students')) {
            return $this->students->pluck('full_name')->join(', ');
        }

        return '';
    }

    public static function getFullLoadRelationships(): array
    {
        return [
            'student',
            'students',
            'school',
            'currency',
            'invoiceItems.invoice.currency',
            'invoiceScholarships.invoice.currency',
            'invoicePaymentSchedule',
            'invoicePaymentSchedules',
            'invoicePaymentSchedules.invoicePaymentTerms',
            'invoicePayments.recordedBy',
            'invoicePayments.currency',
            'invoiceRefunds.currency',
            'invoiceRefunds.user',
            'children',
            'parent',
            'parent.invoicePaymentSchedules.invoicePaymentTerms',
        ];
    }

    public function fullLoad(): static
    {
        return $this->load(static::getFullLoadRelationships());
    }

    public function loadChildren(): static
    {
        if (! $this->is_parent) {
            return $this;
        }

        return $this->load([
            'children.currency',
            'children.student',
            'children.school',
            'children.invoiceItems.invoice.currency',
            'children.invoiceScholarships.invoice.currency',
            'children.invoicePaymentSchedules',
            'children.invoicePaymentSchedules.invoicePaymentTerms',
        ]);
    }

    public static function calculateSubtotalFromItems(Collection $items)
    {
        return $items
            ->reduce(function (int $total, InvoiceItem $item) {
                return $total + $item->calculateTotal();
            }, 0);
    }

    /**
     * This gets the children that can be included
     * in the calculations for the parent invoice
     */
    public function countableChildren(): Collection
    {
        return $this->children
            ->filter(fn (Invoice $child) => $child->shouldBeIncludedInCalculations());
    }

    public function setSubtotal(): static
    {
        $this->subtotal = $this->is_parent
            ? $this->countableChildren()->sum('subtotal')
            : static::calculateSubtotalFromItems($this->invoiceItems);

        return $this;
    }

    public function setDiscountTotal(): static
    {
        if ($this->is_parent) {
            $this->discount_total = $this->countableChildren()->sum('discount_total');

            return $this;
        }

        $discount = $this->invoiceScholarships
            ->reduce(function (int $total, InvoiceScholarship $scholarship) {
                return $total + $scholarship->calculateAmount();
            }, 0);

        $this->discount_total = $discount > $this->subtotal
            ? $this->subtotal
            : $discount;

        return $this;
    }

    public function setPreTaxSubtotal(): static
    {
        if ($this->is_parent) {
            $this->pre_tax_subtotal = $this->countableChildren()->sum('pre_tax_subtotal');

            return $this;
        }

        $subtotal = $this->subtotal - $this->discount_total;

        $this->pre_tax_subtotal = max($subtotal, 0);

        return $this;
    }

    public function setTaxDue(): static
    {
        if ($this->is_parent) {
            $this->tax_due = $this->countableChildren()->sum('tax_due');

            return $this;
        }

        $this->tax_due = 0;

        if (
            $this->school->collect_tax &&
            $this->apply_tax
        ) {
            $this->tax_due = (int) round($this->pre_tax_subtotal * $this->tax_rate);
        }

        return $this;
    }

    public function setAmountDue(): static
    {
        $this->amount_due = $this->is_parent
            ? $this->countableChildren()->sum('amount_due')
            : $this->pre_tax_subtotal + $this->tax_due;

        return $this;
    }

    public function setRemainingBalance(): static
    {
        $amountDue = $this->amount_due;

        // Calculate how much has already been paid in
        // and set the remaining_balance value based on that
        $this->total_paid = $this->getTotalPaid();

        // If the invoice has a schedule, use its amount
        // as the invoice's amount due, which may be different
        // from the invoice's base amount due (likely)
        if ($this->invoice_payment_schedule_uuid) {
            $amountDue = $this->invoicePaymentSchedule->amount;
        }

        $this->remaining_balance = $amountDue - $this->total_paid;
        $this->paid_at = $this->remaining_balance <= 0
            ? now()
            : null;

        return $this;
    }

    public function getTotalPaid(): int
    {
        $paymentsSum = $this->invoicePayments->sum('amount');
        $refundsSum = $this->invoiceRefunds->sum('amount');
        $total = $paymentsSum - $refundsSum;

        // If this isn't the parent of a combined invoice
        // just return the sum of all the payments
        if (! $this->is_parent) {
            return $total;
        }

        // Eager load any payments for the children
        // but not any that have a parent payment
        $this->load([
            'children.invoicePayments' => function ($query) {
                $query->whereNull('invoice_payments.parent_uuid');
            },
            'children.invoiceRefunds',
        ]);

        // Add all the payments made to this invoice
        // and any child invoices since they could be
        // recorded independently of the parent
        $childrenPaymentTotal = $this->countableChildren()->reduce(function (int $total, Invoice $child) {
            return $total + $child->invoicePayments->sum('amount');
        }, $total);
        $childrenRefunds = $this->countableChildren()->reduce(function (int $total, Invoice $child) {
            return $total + $child->invoiceRefunds->sum('amount');
        }, 0);

        return $childrenPaymentTotal - $childrenRefunds;
    }

    public function setCalculatedAttributes(bool $save = false): static
    {
        // If this is a parent invoice, recalculate all the children
        // invoices before recalculating this one
        if ($this->is_parent) {
            $this->loadChildren()
                ->countableChildren()
                ->each(function (Invoice $invoice) use ($save) { // @phpstan-ignore-line
                    $invoice->setCalculatedAttributes($save);
                });
        }

        $this->setSubtotal()
            ->setDiscountTotal()
            ->setPreTaxSubtotal()
            ->setTaxDue()
            ->setAmountDue()
            ->setRemainingBalance();

        if ($save) {
            $this->save();
        }

        return $this;
    }

    public function cacheCalculations(): static
    {
        // Cache all items
        $this->invoiceItems->each(function (InvoiceItem $item) {
            $item->setAmount()->save();
        });

        // Cache all scholarship calculations
        $scholarships = $this->invoiceScholarships()
            ->with('invoice', 'invoice.invoiceItems')
            ->get();
        $scholarships->each(function (InvoiceScholarship $scholarship) {
            $scholarship->setAmount()->save();
        });

        $this->setCalculatedAttributes()
            ->save();

        return $this;
    }

    public function shouldBeIncludedInCalculations(): bool
    {
        return ($this->published_at && $this->published_at <= now()) &&
            ! $this->voided_at;
    }

    public function queueNotification(Carbon $notifyAt): static
    {
        $notifyAt->startOfMinute();

        $this->update([
            'notify' => true,
            'notify_at' => $notifyAt,
            'notified_at' => null,
        ]);

        // Dispatch the notification for 15 minutes
        SendNewInvoiceNotification::dispatch($this->uuid)
            ->delay($notifyAt);

        return $this;
    }

    public function notifyLater(?Carbon $dateTime = null): static
    {
        return $this->queueNotification($dateTime ?? now()->addMinutes(15));
    }

    public function notifyNow(): static
    {
        return $this->queueNotification(now());
    }

    public function cancelNotification(): static
    {
        $this->update([
            'notify' => false,
            'notify_at' => null,
            'notified_at' => null,
        ]);

        return $this;
    }

    public static function getSubmittedItemsTotal(Collection $items, Collection $fees): int
    {
        return $items->reduce(function (int $total, array $item) use ($fees) {
            if ($item['sync_with_fee'] && $fee = $fees->get($item['fee_id'])) {
                $item['amount_per_unit'] = $fee->amount;
            }

            return $total + ($item['amount_per_unit'] * $item['quantity']);
        }, 0);
    }

    /**
     * Takes the invoice and prunes all the fields
     * that aren't required to create an invoice
     */
    public function asInvoiceTemplate(): array
    {
        $this->load(
            'invoiceItems',
            'invoiceScholarships',
            'invoiceScholarships.appliesTo',
            'invoicePaymentSchedules',
            'invoicePaymentSchedules.invoicePaymentTerms',
            'invoiceTaxItems',
            'invoiceTaxItems.invoiceItem',
        );

        $resource = $this->toResource()
            ->response()
            ->getData(true);

        $data = Arr::only($resource, static::$formAttributes);
        $data['title'] = $this->raw_title;

        $data['items'] = array_map(
            fn ($item) => Arr::only($item, InvoiceItem::$formAttributes),
            $data['items'],
        );

        $data['scholarships'] = array_map(
            function ($item) {
                $item['percentage'] = $item['percentage_converted'];

                return Arr::only($item, InvoiceScholarship::$formAttributes);
            },
            $data['scholarships']
        );

        $data['payment_schedules'] = array_map(
            function ($schedule) {
                $schedule['terms'] = array_map(
                    fn ($term) => Arr::only($term, InvoicePaymentTerm::$formAttributes),
                    $schedule['terms']
                );

                return Arr::only($schedule, InvoicePaymentSchedule::$formAttributes);
            },
            $data['payment_schedules']
        );

        // Tax attributes, some need converting
        $data['tax_rate'] = $this->tax_rate_converted;

        $taxItems = $this->invoiceTaxItems->keyBy('invoice_item_uuid');
        $data['tax_items'] = $this->invoiceItems
            ->map(fn (InvoiceItem $item) => [
                'item_id' => $item->uuid,
                'name' => $item->name,
                'selected' => $taxItems->has($item->uuid),
                'tax_rate' => $taxItems->has($item->uuid)
                    ? $taxItems->get($item->uuid)->tax_rate_converted
                    : $this->school->tax_rate_converted,
            ])
            ->toArray();

        return $data;
    }

    public function forEditing(bool $asBatch = false): array
    {
        $template = $this->asInvoiceTemplate();

        $template['uuid'] = $this->uuid;
        $template['batch_id'] = $this->batch_id;
        $template['students'] = $asBatch
            ? static::batch($this->batch_id)
                ->unpublished()
                ->pluck('student_uuid')
                ->toArray()
            : [$this->student_uuid];

        return $template;
    }

    public function convertToInvoiceTemplate(array $data): InvoiceTemplate
    {
        return InvoiceTemplate::create([
            'school_id' => $this->school_id,
            'user_uuid' => auth()->id(),
            'name' => $data['name'] ?? "Created from invoice {$this->number_formatted}",
            'template' => Arr::except($this->asInvoiceTemplate(), 'students'),
            'for_import' => false,
        ]);
    }

    /**
     * Sets the published date on the invoice
     *
     * @return $this
     */
    public function publish(): static
    {
        $this->update(['published_at' => now()]);

        return $this;
    }

    public static function successfullyCreatedResponse(Collection $results): RedirectResponse
    {
        if ($results->count() === 1) {
            session()->flash('success', __('Invoice created successfully.'));
        } else {
            session()->flash('success', __(':count invoices created successfully.', [
                'count' => $results->count(),
            ]));
        }

        $invoice = Invoice::where('uuid', $results->first())
            ->first();

        return redirect()->route('invoices.index', ['batch_id' => $invoice->batch_id]);
    }

    public static function successfullyUpdatedResponse(Collection $results): RedirectResponse
    {
        if ($results->count() === 1) {
            session()->flash('success', __('Invoice updated successfully.'));
        } else {
            session()->flash('success', __(':count invoices updated successfully.', [
                'count' => $results->count(),
            ]));
        }

        $invoice = Invoice::where('uuid', $results->first())
            ->first();

        return redirect()->route('invoices.index', ['batch_id' => $invoice->batch_id]);
    }

    public static function generateInvoiceNumber(int $schoolId, string $prefix = ''): string
    {
        $key = "{$schoolId}_invoice_number";
        // Fetch the count or set it initially
        $currentMax = Cache::remember(
            $key,
            900,
            function () use ($schoolId, $prefix) {
                $number = static::query()
                    ->where('school_id', $schoolId)
                    ->count();
                $numberExists = fn ($number) => static::query()
                    ->where('school_id', $schoolId)
                    ->where('invoice_number', static::makeInvoiceNumber($number, $prefix))
                    ->exists();

                while ($numberExists($number + 1)) {
                    $number++;
                }

                return $number;
            }
        );
        // Increment the value
        cache()->increment($key);
        $invoiceNumber = $currentMax + 1;

        return static::makeInvoiceNumber($invoiceNumber, $prefix);
    }

    public static function makeInvoiceNumber(int $number, string $prefix): string
    {
        return $prefix.Str::padLeft((string) $number, 3, '0');
    }

    public function migrateActivity(string $uuid): static
    {
        $this->activities()
            ->update(['subject_id' => $uuid]);

        return $this;
    }

    public function getSearchResult(): SearchResult
    {
        return new SearchResult(
            $this,
            "{$this->title}: {$this->invoice_number}",
            route('invoices.show', $this)
        );
    }

    public function recordPayment(InvoicePayment $payment, bool $log = true): static
    {
        // Some actions need to be applied to only the parent
        // so if this has a parent, use it. Otherwise,
        // just use the original invoice instance
        $parent = $this->parent_uuid ? $this->parent : $this;

        // If the payment associated a term
        // set the invoice's schedule to the term's
        if ($payment->invoice_payment_term_uuid) {
            $scheduleUuid = $payment->invoicePaymentTerm->invoice_payment_schedule_uuid;
        }

        if ($this->parent_uuid) {
            $this->setRemainingBalance()
                ->save();
        }

        // Only parent invoices should distribute to terms
        $parent->distributePaymentToTerms($payment)
            ->distributePaymentToChildren($payment)
            ->forceFill([
                'invoice_payment_schedule_uuid' => $scheduleUuid ?? $this->invoice_payment_schedule_uuid,
            ])
            ->setRemainingBalance()
            ->save();

        if ($log) {
            $this->logPayment($payment);
        }

        // Save the remaining balance to the payment "at this point in time"
        // Update the DB to prevent events and
        // modifying the updated timestamp
        DB::table($payment->getTable())
            ->where('uuid', $payment->uuid)
            ->update(['remaining_balance' => $parent->remaining_balance]);

        // Update account balance
        if ($this->student_uuid) {
            SetStudentCachedValues::dispatch($this->student_uuid);
        }

        // Generate the receipt
        MakeReceipt::dispatch($payment->uuid);

        return $this;
    }

    public function distributePaymentToChildren(InvoicePayment $payment): static
    {
        // If this isn't a parent invoice
        // Or the payment isn't being applied to the parent invoice
        // Don't distribute to the children because it isn't for the parent
        if (! $this->is_parent || $this->uuid !== $payment->invoice_uuid) {
            return $this;
        }

        // Do in a transaction so nothing weird happens
        DB::transaction(fn () => DB::table('invoice_payments')->insert($this->getChildrenPayments($payment))
        );

        return $this;
    }

    public function getChildrenPayments(InvoicePayment $payment): array
    {
        $children = $this->countableChildren()->keyBy('uuid');
        $remainingBalances = $this->countableChildren()->pluck('remaining_balance', 'uuid');
        $distributions = $children->reduce(function (array $distributions, Invoice $invoice) use ($payment) {
            // This is the ratio of child:parent remaining balance,
            // which we'll use to assign the distribution amount
            $ratio = $this->remaining_balance > 0
                ? $invoice->remaining_balance / $this->remaining_balance
                : 0;
            // Always round down to avoid over-distribution
            // Will make up the difference later
            $distribution = (int) floor($ratio * $payment->amount);

            $distributions[$invoice->uuid] = $distribution < $invoice->remaining_balance
                ? $distribution
                : $invoice->remaining_balance;

            return $distributions;
        }, []);

        // Until the distributions matches the payment amount,
        // add to the invoices' distribution if less than remaining balance
        while ($payment->amount - array_sum($distributions) > 0) {
            // Add to distributions until they equal
            foreach ($distributions as $uuid => $distribution) {
                $remaining = $remainingBalances->get($uuid) - $distribution;

                if ($remaining > 0) {
                    $distributions[$uuid] = $distribution + 1;
                    $remainingBalances[$uuid] = $remaining - 1;
                }

                // If the total distribution matches the payment amount, break now
                if ($payment->amount === array_sum($distributions)) {
                    break;
                }
            }
        }

        $childPayments = [];

        foreach ($children as $child) {
            $amount = $distributions[$child->uuid];

            // Don't add a payment for an invoice that
            // doesn't get a distribution
            if ($amount === 0) {
                continue;
            }

            $childPayments[] = [
                'uuid' => UuidFactory::make(),
                'parent_uuid' => $payment->uuid,
                'invoice_uuid' => $child->uuid,
                'amount' => $amount,
                'original_amount' => $amount,
                'school_id' => $payment->school_id,
                'tenant_id' => $payment->tenant_id,
                'payment_method_id' => $payment->payment_method_id,
                'paid_at' => $payment->paid_at,
                'recorded_by' => $payment->recorded_by,
                'made_by' => $payment->made_by,
                'created_at' => $payment->created_at,
                'updated_at' => $payment->updated_at,
            ];

            $remaining = $child->remaining_balance - $amount;

            $child->update([
                'remaining_balance' => $remaining,
                'paid_at' => $remaining > 0 ? null : now(),
            ]);

            // Update account balances
            if ($child->student_uuid) {
                SetStudentCachedValues::dispatch($child->student_uuid);
            }
        }

        return $childPayments;
    }

    public function distributePaymentsToTerms(bool $reset = false): static
    {
        // If we're resetting, set the remaining balance
        // of all the terms to the original amount due,
        // as if no payment had been made
        if ($reset) {
            $this->invoicePaymentTerms()
                ->update(['remaining_balance' => DB::raw('invoice_payment_terms.amount_due')]);
        }

        $this->load('invoicePayments');

        foreach ($this->invoicePayments as $payment) {
            $this->distributePaymentToTerms($payment, false);
        }

        // Save after all the payments processed
        $this->invoicePaymentSchedules
            ->each(function (InvoicePaymentSchedule $schedule) {
                $schedule->invoicePaymentTerms
                    ->each(function (InvoicePaymentTerm $term) {
                        $term->save();
                    });
            });

        return $this;
    }

    public function distributePaymentToTerms(InvoicePayment $payment, bool $save = true): static
    {
        // Load all the payment schedules to automatically distribute the
        // payment across all the terms
        if (! $this->relationLoaded('invoicePaymentSchedules')) {
            $this->load('invoicePaymentSchedules', 'invoicePaymentSchedules.invoicePaymentTerms');
        }

        // Use the payment and update all the terms' remaining balances
        // as if it was being applied to each payment schedule's terms
        foreach ($this->invoicePaymentSchedules as $schedule) {
            // Each schedule has its own amount due, since it's likely different
            // from the base amount due. We need to apply this payment to each
            // schedule and its terms
            $remainingSchedulePayment = $payment->amount;

            foreach ($schedule->invoicePaymentTerms as $term) {
                if ($term->remaining_balance === 0) {
                    continue;
                }

                // Stop iterating if there's no more payment
                // to distribute to the terms
                if ($remainingSchedulePayment <= 0) {
                    break;
                }

                $originalRemainingBalance = $term->remaining_balance;
                $remainingTermBalance = $term->remaining_balance - $remainingSchedulePayment;

                $term->fill([
                    'remaining_balance' => max($remainingTermBalance, 0),
                ]);

                if ($save) {
                    $term->save();
                }

                $remainingSchedulePayment -= abs($term->remaining_balance - $originalRemainingBalance);
            }
        }

        return $this;
    }

    public function logPayment(InvoicePayment $payment): static
    {
        // __(':user recorded a payment of :amount')
        // __(':user recorded a payment of :amount made by :made_by')
        $description = $payment->made_by
            ? ':user recorded a payment of :amount made by :made_by'
            : ':user recorded a payment of :amount';
        $payment->setRelation('currency', $this->currency);
        $properties = [
            'amount' => $payment->amount_formatted,
            'made_by' => optional($payment->madeBy)->full_name,
            'invoice_number' => $this->invoice_number,
        ];

        activity()
            ->on($this)
            ->withProperties($properties)
            ->component('CashIcon')
            ->log($description);

        // If this is a child invoice, log details to the parent invoice
        if ($this->parent_uuid) {
            // __(':user recorded a payment of :amount made to :invoice_number')
            // __(':user recorded a payment of :amount made to :invoice_number by :made_by')
            $description = $payment->made_by
                ? ':user recorded a payment of :amount made to :invoice_number by :made_by'
                : ':user recorded a payment of :amount made to :invoice_number';

            activity()
                ->on($this->parent)
                ->withProperties($properties)
                ->component('CashIcon')
                ->log($description);
        }

        return $this;
    }

    public static function getPdfDisk(): Filesystem|FilesystemAdapter
    {
        return Storage::disk(config('filesystems.invoices'));
    }

    public function generatePdfPath(): string
    {
        $parameters = [
            $this->tenant_id,
            $this->school_id,
            $this->created_at->format('Y'),
            $this->created_at->format('n'),
            $this->invoice_number,
            $this->invoice_number,
            now()->format('Ymd-Hi-s').'-'.Str::uuid(),
        ];

        return Str::replaceArray('?', $parameters, '?/?/?/?/?/?-?.pdf');
    }

    public function savePdf(?InvoiceLayout $layout = null): InvoicePdf
    {
        $this->load([
            'invoiceItems',
            'invoiceScholarships.appliesTo',
            'invoicePaymentSchedules.invoicePaymentTerms',
        ]);
        $layout = $layout ?? $this->school->getDefaultInvoiceLayout();
        $title = __('Invoice #:number', ['number' => $this->id]);

        $content = view('invoice', [
            'layout' => $layout,
            'invoice' => $this,
            'title' => $title,
            'currency' => $this->currency,
        ])->render();

        $userDir = realpath(sys_get_temp_dir()."/sonar-fms-pdf/layout-{$layout->id}");
        $disk = static::getPdfDisk();
        $path = $this->generatePdfPath();

        $disk->makeDirectory(dirname($path));

        PdfBrowserFactory::make($content)
            ->disableJavascript()
            ->margins(0, 0, 0, 0)
            ->format($layout->paper_size)
            ->noSandbox()
            ->showBackground()
            ->addChromiumArguments([
                'user-data-dir' => $userDir,
            ])
            ->ignoreHttpsErrors()
            ->hideHeader()
            ->hideFooter()
            ->savePdf($disk->path($path));

        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->invoicePdfs()->create([
            'tenant_id' => $this->tenant_id,
            'school_id' => $this->school_id,
            'user_uuid' => auth()->user()?->uuid,
            'invoice_layout_id' => $layout->id,
            'name' => basename($path),
            'relative_path' => $path,
        ]);
    }

    public function fakeSavePdf(?InvoiceLayout $layout = null): InvoicePdf
    {
        $layout = $layout ?? $this->school->getDefaultInvoiceLayout();
        $disk = static::getPdfDisk();
        $path = $this->generatePdfPath();
        $disk->write($path, '');

        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->invoicePdfs()->create([
            'tenant_id' => $this->tenant_id,
            'school_id' => $this->school_id,
            'user_uuid' => auth()->user()?->uuid,
            'invoice_layout_id' => $layout->id,
            'name' => basename($path),
            'relative_path' => $path,
        ]);
    }

    public function latestPdf(bool $recreate = false): InvoicePdf
    {
        if ($recreate) {
            return $this->savePdf();
        }

        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->invoicePdfs()
            ->latest()
            ->first() ?? $this->savePdf();
    }

    public function recordRefund(SaveRefundRequest $request): InvoiceRefund
    {
        $validated = $request->validated();
        $validated['user_uuid'] = $request->user()->uuid;
        $validated['school_id'] = $request->school()->id;
        $validated['tenant_id'] = $request->tenant()->id;

        /** @var InvoiceRefund $refund */
        $refund = $this->invoiceRefunds()->create($validated);

        $this->setCalculatedAttributes(true);

        // __(':user recorded a refund for :amount.')
        activity()
            ->on($this)
            ->withProperties([
                'amount' => displayCurrency($refund->amount, $this->currency),
            ])
            ->component('ReceiptRefundIcon')
            ->log(':user recorded a refund for :amount.');

        if ($this->parent_uuid) {
            // __(':user recorded a refund for :amount for :invoice_number.')
            activity()
                ->on($this->parent)
                ->withProperties([
                    'amount' => displayCurrency($refund->amount, $this->currency),
                    'invoice_number' => $this->invoice_number,
                ])
                ->component('ReceiptRefundIcon')
                ->log(':user recorded a refund for :amount for :invoice_number.');

            CalculateInvoiceAttributes::dispatch($this->parent_uuid);
        }

        return $refund;
    }

    public static function getExportHeadings(): array
    {
        return [
            __('Invoice number'),
            __('Combined invoice number'),
            __('Student'),
            __('Student number'),
            __('Grade'),
            //            __('Contact'),
            //            __('Contact email'),
            __('Title'),
            __('Status'),
            __('Invoice date'),
            __('Availability'),
            __('Due date'),
            __('Voided'),
            __('Term'),
            __('Amount due'),
            __('Remaining balance'),
        ];
    }

    public function toRow(): array
    {
        return [
            $this->invoice_number,
            $this->parent?->invoice_number,
            $this->student?->full_name,
            $this->student?->student_number,
            $this->student?->grade_level_short_formatted,
            //            $this->user?->full_name,
            //            $this->user?->email,
            $this->title,
            $this->status_label,
            $this->invoice_date?->format('Y-m-d'),
            $this->available_at?->format('Y-m-d'),
            $this->due_at?->format('Y-m-d'),
            $this->voided_at?->format('Y-m-d'),
            $this->term?->name,
            $this->amount_due_formatted,
            $this->remaining_balance_formatted,
        ];
    }

    public function getExportRow(): array
    {
        $row = $this->toRow();

        if ($this->children->isEmpty()) {
            return $row;
        }

        return [
            $row,
            ...$this->children->map(
                fn (Invoice $invoice) => $invoice->toRow()
            )->toArray(),
        ];
    }

    public static function getExportQuery(RecordExport $export): \Illuminate\Database\Query\Builder|Builder|Relation
    {
        $query = $export->user->can('view', Invoice::class)
            ? $export->school
                ->invoices()
                ->notAChild()
            : Invoice::forUser($export->user)
                ->published();

        $query->orderBy('invoice_date', 'desc')
            ->with([
                'student',
                'students',
                'currency',
                'term',
                'children.student',
                'children.parent',
                'children.students',
                'children.currency',
            ]);

        if ($export->apply_filters) {
            $query->filter($export->filters);
        }

        return $query;
    }

    public static function getValidationRules(): array
    {
        return [
            'title' => ['required', 'max:255'],
            'due_at' => ['nullable', 'date'],
            'invoice_date' => ['date'],
            'available_at' => ['nullable', 'date'],
            'notify' => ['nullable', 'boolean'],
            'tax_rate' => ['nullable', 'numeric'],
        ];
    }

    public function isPublic(): bool
    {
        return $this->published_at &&
            $this->available_at <= now();
    }
}
