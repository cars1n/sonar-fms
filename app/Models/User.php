<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasResource;
use App\Traits\UsesUuid;
use Carbon\Factory;
use GrantHolle\PowerSchool\Api\Facades\PowerSchool;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\Database\HasRolesAndAbilities;
use Spatie\Activitylog\Traits\CausesActivity;

/**
 * @mixin IdeHelperUser
 */
class User extends Authenticatable implements HasLocalePreference
{
    use BelongsToTenant;
    use CausesActivity;
    use HasFactory;
    use HasResource;
    use HasRolesAndAbilities;
    use Notifiable;
    use UsesUuid;

    const TEACHER = 'teacher';

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'manages_tenancy' => 'boolean',
        'needs_to_register' => 'boolean',
        'sis_id' => 'int',
        'contact_id' => 'int',
        'guardian_id' => 'int',
        'registered_at' => 'datetime',
    ];

    public function getAuthPassword(): string
    {
        return (string) parent::getAuthPassword();
    }

    public function scopeFilter(Builder $builder, array $filters)
    {
        $builder->when($filters['s'] ?? null, function (Builder $builder, string $search) {
            $builder->where(function (Builder $builder) use ($search) {
                $builder->where(DB::raw("concat(first_name, ' ', last_name)"), 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "${search}%");
            });
        });

        $orderBy = $filters['orderBy'] ?? 'last_name';
        $builder->orderBy($orderBy, $filters['orderDir'] ?? 'asc');
        $builder->orderBy('first_name', $filters['orderDir'] ?? 'asc');
    }

    /**
     * Gets the users who have an ability directly or through a role
     */
    public function scopeWhereCan(Builder $query, string $ability)
    {
        $query->where(function ($query) use ($ability) {
            // direct
            $query->whereHas('abilities', function ($query) use ($ability) {
                $query->byName($ability);
            });
            // through roles
            $query->orWhereHas('roles', function ($query) use ($ability) {
                $query->whereHas('abilities', function ($query) use ($ability) {
                    $query->byName($ability);
                });
            });
        });
    }

    public function getSchoolPermissionsAttribute(): array
    {
        return $this->getPermissionsForSchool();
    }

    public function getTimeFormatAttribute($value): string
    {
        return (string) $value;
    }

    public function getCarbonTimeAttribute(): string
    {
        $formats = [
            '12' => 'g:ia',
            '24' => 'G:i',
        ];

        return $formats[$this->time_format] ?? $formats['12'];
    }

    public function getStudentSelectionAttribute(): Collection
    {
        if ($this->relationLoaded('studentSelections')) {
            return $this->studentSelections->pluck('student_uuid');
        }

        return collect();
    }

    public function getInvoiceSelectionAttribute(): Collection
    {
        if ($this->relationLoaded('invoiceSelections')) {
            return $this->invoiceSelections->pluck('invoice_uuid');
        }

        return collect();
    }

    public function getIsSchoolAdminAttribute(): bool
    {
        return $this->isSchoolAdmin();
    }

    public function getFullNameAttribute(): string
    {
        return $this->first_name.' '.$this->last_name;
    }

    public function getDateFactoryAttribute(): Factory
    {
        return new Factory([
            'locale' => $this->locale,
            'timezone' => $this->timezone,
        ]);
    }

    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class)
            ->withPivot(['staff_id']);
    }

    public function activeSchools(): BelongsToMany
    {
        return $this->schools()
            ->where('active', true);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class)
            ->withPivot('relationship');
    }

    public function studentSelections(): HasMany
    {
        return $this->hasMany(StudentSelection::class)
            ->join('users', function (JoinClause $join) {
                $join->on('student_selections.user_uuid', '=', 'users.uuid');
            })
            ->whereRaw('student_selections.school_id = users.school_id');
    }

    public function selectedStudents(): HasManyThrough
    {
        return $this->hasManyThrough(
            Student::class,
            StudentSelection::class,
            'student_uuid',
            'id',
            'id',
            'user_uuid'
        );
    }

    public function getSelectedStudentsAttribute(): Collection
    {
        return Student::join('student_selections', 'student_uuid', '=', 'students.uuid')
            ->where('student_selections.school_id', $this->school_id)
            ->where('student_selections.user_uuid', $this->id)
            ->get();
    }

    public function invoiceSelections(): HasMany
    {
        return $this->hasMany(InvoiceSelection::class)
            ->join('users', function (JoinClause $join) {
                $join->on('invoice_selections.user_uuid', '=', 'users.uuid');
            })
            ->whereRaw('invoice_selections.school_id = users.school_id');
    }

    public function selectedInvoices(): HasManyThrough
    {
        return $this->hasManyThrough(
            Invoice::class,
            InvoiceSelection::class,
            'user_uuid',
            'uuid',
            'id',
            'invoice_uuid'
        )->where('invoice_selections.school_id', $this->school_id);
    }

    public function invoiceImports(): HasMany
    {
        return $this->hasMany(InvoiceImport::class);
    }

    public function paymentImports(): HasMany
    {
        return $this->hasMany(PaymentImport::class);
    }

    public function paymentImportTemplates(): HasMany
    {
        return $this->hasMany(PaymentImportTemplate::class);
    }

    public function invoiceTemplates(): HasMany
    {
        return $this->hasMany(InvoiceTemplate::class);
    }

    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class);
    }

    public function getPermissionsForSchool(?School $school = null): array
    {
        $school = $school ?? $this->school;

        return [
            [
                'label' => __('Change school settings'),
                'permission' => 'change settings',
                'selected' => $this->can('change settings', $school),
            ],
        ];
    }

    /**
     * Adds a student to a user's selection
     */
    public function selectStudent(Student|string $studentId): User
    {
        if ($studentId instanceof Student) {
            $studentId = $studentId->uuid;
        }

        $exists = $this->studentSelections()
            ->student($studentId)
            ->exists();

        if (! $exists) {
            DB::table('student_selections')->insert([
                'school_id' => $this->school_id,
                'user_uuid' => $this->id,
                'student_uuid' => $studentId,
            ]);
        }

        return $this;
    }

    public function selectInvoice(Invoice|string $invoiceUuid): static
    {
        if ($invoiceUuid instanceof Invoice) {
            $invoiceUuid = $invoiceUuid->uuid;
        }

        $exists = $this->invoiceSelections()
            ->invoice($invoiceUuid)
            ->exists();

        if (! $exists) {
            DB::table('invoice_selections')->insert([
                'school_id' => $this->school_id,
                'user_uuid' => $this->id,
                'invoice_uuid' => $invoiceUuid,
            ]);
        }

        return $this;
    }

    public function setSchool(): static
    {
        if (
            ! $this->school_id ||
            ! $this->schools->contains('id', $this->school_id)
        ) {
            $school = $this->schools->first();

            // If they're not assigned a school yet,
            // check their students' for a school to assign
            if (! $school) {
                $school = $this->students()
                    ->whereHas('school')
                    ->first();
            }

            // If they still don't have a school,
            // check if there's only one school for the tenant
            if (! $school && $this->tenant->schools()->count() === 1) {
                $school = $this->tenant->schools()
                    ->first();
            }

            $this->school_id = $school?->id;
        }

        return $this;
    }

    public function setSchoolStaffSchools(): static
    {
        if (! $this->sis_id) {
            return $this;
        }

        $schoolNumbers = PowerSchool::pq('com.archboard.sonarfms.staff.schools', ['dcid' => $this->sis_id])
            ->collect()
            ->pluck('schoolid');

        $schools = School::whereIn('school_number', $schoolNumbers)->pluck('id');
        $this->schools()->syncWithoutDetaching($schools);

        return $this;
    }

    /**
     * This does a complete sync of a Contact's
     * student access and sets Bouncer permissions
     *
     * @return $this
     */
    public function syncStudents(): static
    {
        if (! $this->contact_id) {
            return $this;
        }

        $contactStudents = PowerSchool::get("/ws/contacts/{$this->contact_id}/students")
            ->collect();
        $hasDataAccess = $contactStudents->some('canAccessData', true);

        // Check to see if this user doesn't have data access
        // to any of the students. If the conditions are right
        // they will need to receive registration access
        // to ensure they can access invoices
        if (! $hasDataAccess && ! $this->password) {
            $this->update(['needs_to_register' => true]);
        }

        $students = Student::whereIn('sis_id', $contactStudents->pluck('dcid'))
            ->pluck('uuid', 'sis_id');
        $schools = School::whereIn('school_number', $contactStudents->pluck('schoolNumber'))
            ->pluck('id', 'school_number');

        // We're doing a clean sync because Contacts
        // is the single source of truth of access.
        // Remove access and then delete directly.
        $this->students
            ->each(fn (Student $student) => $student->revokeGuardianViewPermissions(collect([$this])));
        $this->unsetRelation('students');

        DB::table('student_user')
            ->where('user_uuid', $this->uuid)
            ->delete();

        // Attach the school relationship
        $this->schools()->syncWithoutDetaching($schools->values());

        $studentUsers = $contactStudents->reduce(function ($studentUsers, $student) use ($schools, $students) {
            $studentUuid = $students->get($student['dcid']);

            // If the school doesn't exist
            // or the student doesn't exist
            // don't do anything
            if (
                ! $schools->has($student['schoolNumber']) ||
                ! $studentUuid
            ) {
                return $studentUsers;
            }

            $studentUsers[] = [
                'student_uuid' => $studentUuid,
                'user_uuid' => $this->uuid,
                'relationship' => ($student['studentDetails'][0] ?? null)
                    ? $student['studentDetails'][0]['relationship']
                    : null,
            ];

            return $studentUsers;
        }, []);

        // Re-associate all the students
        DB::table('student_user')->insert($studentUsers);

        // Give fresh permission for these students
        $this->students()
            ->each(fn (Student $student) => $student->grantGuardianViewPermissions(collect([$this])));

        return $this;
    }

    public function setContactId(): static
    {
        if (! $this->contact_id && $this->guardian_id) {
            $response = PowerSchool::pq('com.archboard.sonarfms.guardian.contactid', ['guardianid' => $this->guardian_id]);

            if ($response->count() === 1) {
                $this->update(['contact_id' => $response[0]['personid']]);
            }
        }

        return $this;
    }

    /**
     * Gets all the schools of the tenancy and
     * a flag of whether the user has access
     *
     * Used for assigning schools in users
     */
    public function getSchoolAccessList(): Collection
    {
        return School::orderBy('name')
            ->get()
            ->map(fn (School $school) => [
                'id' => $school->id,
                'name' => $school->name,
                'active' => $school->active,
                'has_access' => $this->schools->contains('id', $school->id),
            ]);
    }

    /**
     * Assigns a permission to a user for a school
     */
    public function givePermissionForSchool(School $school, string $permission = '*', Model|string $model = '*'): static
    {
        \Bouncer::scope()
            ->onceTo($school->id, fn () => $this->allow($permission, $model));

        \Bouncer::refreshFor($this);

        return $this;
    }

    /**
     * This gets the permissions from the permission matrix
     * based on the given model. It will pull the set of
     * permissions for the model as a flattened array
     * of [permission name] => true/false
     */
    public function getPermissions(string $model): array
    {
        $matrix = $this->getPermissionsMatrix();

        $permissions = collect($matrix['models'])
            ->first(fn ($set) => $set['model'] === $model);

        if (! $permissions) {
            return [];
        }

        return collect($permissions['permissions'])
            ->mapWithKeys(fn ($perm) => [$perm['permission'] => $perm['can']])
            ->toArray();
    }

    public function isSchoolAdmin(): bool
    {
        return $this->isA('school admin');
    }

    public function getCarbonFactory(): Factory
    {
        return new Factory([
            'locale' => $this->locale,
            'timezone' => $this->timezone,
        ]);
    }

    public function getSelectionSuggestedUsers(): Collection
    {
        return static::whereHas('students', function (Builder $builder) {
            $builder->whereIn('students.uuid', $this->selectedInvoices->pluck('student_uuid'));
        })
            ->orderBy('last_name')
            ->get();
    }

    public function ownsComment(Comment $comment): bool
    {
        return $this->uuid === $comment->user_id;
    }

    public function getMyStudents(): Collection
    {
        return $this->students()
            ->with('currency')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    public function canViewInvoice(Invoice $invoice): bool
    {
        // Admins can view any invoice,
        // otherwise check if it's published and
        // other parameters about the invoice
        return $this->can('view', $invoice) ||
            (
                $invoice->isPublic() &&
                $this->hasImplicitAccessToInvoice($invoice)
            );
    }

    public function hasImplicitAccessToInvoice(Invoice $invoice): bool
    {
        return (
            $invoice->student_uuid &&
            $this->students()
                ->where('students.uuid', $invoice->student_uuid)
                ->exists()
        ) ||
        (
            $invoice->users()
                ->where('users.uuid', $this->uuid)
                ->exists()
        );
    }

    public function getPermissionsMatrix(): array
    {
        return [
            'manages_tenancy' => $this->manages_tenancy,
            'manages_school' => $this->isSchoolAdmin(),
            'roles' => [],
            'models' => [
                [
                    'model' => User::class,
                    'label' => __('Users'),
                    'permissions' => [
                        [
                            'permission' => 'view',
                            'label' => __('View'),
                            'can' => $this->can('view', User::class),
                        ],
                        [
                            'permission' => 'create',
                            'label' => __('Create'),
                            'can' => $this->can('create', User::class),
                        ],
                        [
                            'permission' => 'update',
                            'label' => __('Update'),
                            'can' => $this->can('update', User::class),
                        ],
                        [
                            'permission' => 'delete',
                            'label' => __('Delete'),
                            'can' => $this->can('delete', User::class),
                        ],
                        [
                            'permission' => 'edit permissions',
                            'label' => __('Edit permissions'),
                            'can' => $this->can('edit permissions', User::class),
                        ],
                    ],
                ],
                [
                    'model' => Student::class,
                    'label' => __('Students'),
                    'permissions' => [
                        [
                            'permission' => 'view',
                            'label' => __('View'),
                            'can' => $this->can('view', Student::class),
                        ],
                        [
                            'permission' => 'create',
                            'label' => __('Create'),
                            'can' => $this->can('create', Student::class),
                        ],
                        [
                            'permission' => 'update',
                            'label' => __('Update'),
                            'can' => $this->can('update', Student::class),
                        ],
                        [
                            'permission' => 'delete',
                            'label' => __('Delete'),
                            'can' => $this->can('delete', Student::class),
                        ],
                        [
                            'permission' => 'comment',
                            'label' => __('Comment'),
                            'can' => $this->can('comment', Student::class),
                        ],
                    ],
                ],
                [
                    'model' => FeeCategory::class,
                    'label' => __('Fee categories'),
                    'permissions' => [
                        [
                            'permission' => 'view',
                            'label' => __('View'),
                            'can' => $this->can('view', FeeCategory::class),
                        ],
                        [
                            'permission' => 'create',
                            'label' => __('Create'),
                            'can' => $this->can('create', FeeCategory::class),
                        ],
                        [
                            'permission' => 'update',
                            'label' => __('Update'),
                            'can' => $this->can('update', FeeCategory::class),
                        ],
                        [
                            'permission' => 'delete',
                            'label' => __('Delete'),
                            'can' => $this->can('delete', FeeCategory::class),
                        ],
                    ],
                ],
                [
                    'model' => Department::class,
                    'label' => __('Departments'),
                    'permissions' => [
                        [
                            'permission' => 'view',
                            'label' => __('View'),
                            'can' => $this->can('view', Department::class),
                        ],
                        [
                            'permission' => 'create',
                            'label' => __('Create'),
                            'can' => $this->can('create', Department::class),
                        ],
                        [
                            'permission' => 'update',
                            'label' => __('Update'),
                            'can' => $this->can('update', Department::class),
                        ],
                        [
                            'permission' => 'delete',
                            'label' => __('Delete'),
                            'can' => $this->can('delete', Department::class),
                        ],
                    ],
                ],
                [
                    'model' => Fee::class,
                    'label' => __('Fees'),
                    'permissions' => [
                        [
                            'permission' => 'view',
                            'label' => __('View'),
                            'can' => $this->can('view', Fee::class),
                        ],
                        [
                            'permission' => 'create',
                            'label' => __('Create'),
                            'can' => $this->can('create', Fee::class),
                        ],
                        [
                            'permission' => 'update',
                            'label' => __('Update'),
                            'can' => $this->can('update', Fee::class),
                        ],
                        [
                            'permission' => 'delete',
                            'label' => __('Delete'),
                            'can' => $this->can('delete', Fee::class),
                        ],
                    ],
                ],
                [
                    'model' => Scholarship::class,
                    'label' => __('Scholarships'),
                    'permissions' => [
                        [
                            'permission' => 'view',
                            'label' => __('View'),
                            'can' => $this->can('view', Scholarship::class),
                        ],
                        [
                            'permission' => 'create',
                            'label' => __('Create'),
                            'can' => $this->can('create', Scholarship::class),
                        ],
                        [
                            'permission' => 'update',
                            'label' => __('Update'),
                            'can' => $this->can('update', Scholarship::class),
                        ],
                        [
                            'permission' => 'delete',
                            'label' => __('Delete'),
                            'can' => $this->can('delete', Scholarship::class),
                        ],
                    ],
                ],
                [
                    'model' => Invoice::class,
                    'label' => __('Invoices'),
                    'permissions' => [
                        [
                            'permission' => 'view',
                            'label' => __('View'),
                            'can' => $this->can('view', Invoice::class),
                        ],
                        [
                            'permission' => 'create',
                            'label' => __('Create'),
                            'can' => $this->can('create', Invoice::class),
                        ],
                        [
                            'permission' => 'update',
                            'label' => __('Update'),
                            'can' => $this->can('update', Invoice::class),
                        ],
                        [
                            'permission' => 'delete',
                            'label' => __('Delete'),
                            'can' => $this->can('delete', Invoice::class),
                        ],
                    ],
                ],
                [
                    'model' => InvoiceImport::class,
                    'label' => __('Invoice imports'),
                    'permissions' => [
                        [
                            'permission' => 'view',
                            'label' => __('View'),
                            'can' => $this->can('view', InvoiceImport::class),
                        ],
                        [
                            'permission' => 'create',
                            'label' => __('Create'),
                            'can' => $this->can('create', InvoiceImport::class),
                        ],
                        [
                            'permission' => 'update',
                            'label' => __('Update'),
                            'can' => $this->can('update', InvoiceImport::class),
                        ],
                        [
                            'permission' => 'delete',
                            'label' => __('Delete'),
                            'can' => $this->can('delete', InvoiceImport::class),
                        ],
                        [
                            'permission' => 'roll back',
                            'label' => __('Roll back'),
                            'can' => $this->can('roll back', InvoiceImport::class),
                        ],
                    ],
                ],
                [
                    'model' => InvoicePayment::class,
                    'label' => __('Invoice payments'),
                    'permissions' => [
                        [
                            'permission' => 'view',
                            'label' => __('View'),
                            'can' => $this->can('view', InvoicePayment::class),
                        ],
                        [
                            'permission' => 'create',
                            'label' => __('Create'),
                            'can' => $this->can('create', InvoicePayment::class),
                        ],
                        [
                            'permission' => 'update',
                            'label' => __('Update'),
                            'can' => $this->can('update', InvoicePayment::class),
                        ],
                        [
                            'permission' => 'delete',
                            'label' => __('Delete'),
                            'can' => $this->can('delete', InvoicePayment::class),
                        ],
                    ],
                ],
                [
                    'model' => Receipt::class,
                    'label' => __('Receipts'),
                    'permissions' => [
                        [
                            'permission' => 'view',
                            'label' => __('View'),
                            'can' => $this->can('view', Receipt::class),
                        ],
                        [
                            'permission' => 'create',
                            'label' => __('Create'),
                            'can' => $this->can('create', Receipt::class),
                        ],
                        [
                            'permission' => 'update',
                            'label' => __('Update'),
                            'can' => $this->can('update', Receipt::class),
                        ],
                        [
                            'permission' => 'delete',
                            'label' => __('Delete'),
                            'can' => $this->can('delete', Receipt::class),
                        ],
                    ],
                ],
                [
                    'model' => PaymentImport::class,
                    'label' => __('Payment imports'),
                    'permissions' => [
                        [
                            'permission' => 'view',
                            'label' => __('View'),
                            'can' => $this->can('view', PaymentImport::class),
                        ],
                        [
                            'permission' => 'create',
                            'label' => __('Create'),
                            'can' => $this->can('create', PaymentImport::class),
                        ],
                        [
                            'permission' => 'update',
                            'label' => __('Update'),
                            'can' => $this->can('update', PaymentImport::class),
                        ],
                        [
                            'permission' => 'delete',
                            'label' => __('Delete'),
                            'can' => $this->can('delete', PaymentImport::class),
                        ],
                        [
                            'permission' => 'roll back',
                            'label' => __('Roll back'),
                            'can' => $this->can('roll back', PaymentImport::class),
                        ],
                    ],
                ],
                [
                    'model' => InvoiceRefund::class,
                    'label' => __('Invoice refunds'),
                    'permissions' => [
                        [
                            'permission' => 'view',
                            'label' => __('View'),
                            'can' => $this->can('view', InvoiceRefund::class),
                        ],
                        [
                            'permission' => 'create',
                            'label' => __('Create'),
                            'can' => $this->can('create', InvoiceRefund::class),
                        ],
                        [
                            'permission' => 'update',
                            'label' => __('Update'),
                            'can' => $this->can('update', InvoiceRefund::class),
                        ],
                        [
                            'permission' => 'delete',
                            'label' => __('Delete'),
                            'can' => $this->can('delete', InvoiceRefund::class),
                        ],
                        [
                            'permission' => 'restore',
                            'label' => __('Restore'),
                            'can' => $this->can('restore', InvoiceRefund::class),
                        ],
                        [
                            'permission' => 'forceDelete',
                            'label' => __('Delete permanently'),
                            'can' => $this->can('forceDelete', InvoiceRefund::class),
                        ],
                    ],
                ],
                [
                    'model' => InvoiceLayout::class,
                    'label' => __('Invoice layouts'),
                    'permissions' => [
                        [
                            'permission' => 'view',
                            'label' => __('View'),
                            'can' => $this->can('view', InvoiceLayout::class),
                        ],
                        [
                            'permission' => 'create',
                            'label' => __('Create'),
                            'can' => $this->can('create', InvoiceLayout::class),
                        ],
                        [
                            'permission' => 'update',
                            'label' => __('Update'),
                            'can' => $this->can('update', InvoiceLayout::class),
                        ],
                        [
                            'permission' => 'delete',
                            'label' => __('Delete'),
                            'can' => $this->can('delete', InvoiceLayout::class),
                        ],
                    ],
                ],
                [
                    'model' => ReceiptLayout::class,
                    'label' => __('Receipt layouts'),
                    'permissions' => [
                        [
                            'permission' => 'view',
                            'label' => __('View'),
                            'can' => $this->can('view', ReceiptLayout::class),
                        ],
                        [
                            'permission' => 'create',
                            'label' => __('Create'),
                            'can' => $this->can('create', ReceiptLayout::class),
                        ],
                        [
                            'permission' => 'update',
                            'label' => __('Update'),
                            'can' => $this->can('update', ReceiptLayout::class),
                        ],
                        [
                            'permission' => 'delete',
                            'label' => __('Delete'),
                            'can' => $this->can('delete', ReceiptLayout::class),
                        ],
                    ],
                ],
                [
                    'model' => PaymentMethod::class,
                    'label' => __('Payment methods'),
                    'permissions' => [
                        [
                            'permission' => 'view',
                            'label' => __('View'),
                            'can' => $this->can('view', PaymentMethod::class),
                        ],
                        [
                            'permission' => 'create',
                            'label' => __('Create'),
                            'can' => $this->can('create', PaymentMethod::class),
                        ],
                        [
                            'permission' => 'update',
                            'label' => __('Update'),
                            'can' => $this->can('update', PaymentMethod::class),
                        ],
                    ],
                ],
            ],
        ];
    }
}
