<?php

namespace App\SisProviders;

use App\Events\SchoolSyncComplete;
use App\Factories\UuidFactory;
use App\Models\Course;
use App\Models\School;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use GrantHolle\PowerSchool\Api\RequestBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PowerSchoolProvider implements SisProvider
{
    protected Tenant $tenant;

    protected RequestBuilder $builder;

    public function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
        $this->builder = new RequestBuilder(
            $tenant->ps_url,
            $tenant->ps_client_id,
            $tenant->ps_secret
        );
    }

    public function getSchoolsFromSis(): array
    {
        if (config('app.cloud')) {
            return $this->tenant->schools->toArray();
        }

        $response = $this->builder
            ->to('/ws/v1/district/school')
            ->get();

        return array_map(
            fn ($school) => [
                'sis_id' => $school['id'],
                'name' => $school['name'],
                'school_number' => $school['school_number'],
                'low_grade' => $school['low_grade'],
                'high_grade' => $school['high_grade'],
            ],
            $response->toArray()
        );
    }

    public function getAllSchools(): array
    {
        return $this->getSchoolsFromSis();
    }

    public function syncSchools(): Collection
    {
        return collect($this->getAllSchools())
            ->map(function (array $school) {
                return $this->tenant
                    ->schools()
                    ->updateOrCreate(
                        Arr::only($school, 'sis_id'),
                        Arr::except($school, 'sis_id')
                    );
            });
    }

    public function getSchool($sisId, bool $force = false)
    {
        if ($sisId instanceof School) {
            $sisId = $sisId->sis_id;
        }

        if (
            config('app.cloud') &&
            ! $force &&
            ! $this->tenant->schools()->where('sis_id', $sisId)->exists()
        ) {
            throw new \Exception('Your license does not support this school. Please update your license and try again.');
        }

        return $this->builder
            ->to("/ws/v1/school/{$sisId}")
            ->get();
    }

    public function syncSchool($sisId, bool $force = false): School
    {
        $sisSchool = $this->getSchool($sisId, $force);

        /** @var School $school */
        $school = $this->tenant
            ->schools()
            ->updateOrCreate(
                ['sis_id' => $sisSchool['id']],
                [
                    'name' => $sisSchool['name'],
                    'school_number' => $sisSchool['school_number'],
                    'low_grade' => $sisSchool['low_grade'],
                    'high_grade' => $sisSchool['high_grade'],
                ]
            );

        return $school;
    }

    public function syncSchoolStaff($sisId)
    {
        $school = $this->tenant->getSchoolFromSisId($sisId);
        $builder = $this->builder
            ->method('get')
            ->to("/ws/v1/school/{$school->sis_id}/staff")
            ->expansions('emails');
        $newUsers = collect();
        $schoolUser = collect();

        while ($results = $builder->paginate()) {
            $now = now()->format('Y-m-d H:i:s');
            $existingUsers = $this->tenant
                ->users()
                ->whereIn('sis_id', collect($results)->pluck('users_dcid'))
                ->with('schools')
                ->get()
                ->keyBy('sis_id');

            foreach ($results as $user) {
                $email = strtolower($user['emails']['work_email'] ?? '');

                if (! $email) {
                    continue;
                }

                /** @var User $existingUser */
                if ($existingUser = $existingUsers->get($user['users_dcid'])) {
                    $existingUser->update([
                        'email' => $email,
                        'first_name' => $user['name']['first_name'] ?? null,
                        'last_name' => $user['name']['last_name'] ?? null,
                    ]);
                    /** @var School|null $existingSchool */
                    $existingSchool = $existingUser->schools->firstWhere('id', $school->id);

                    // If the school record exists already, update the staff id just in case
                    if ($existingSchool && $existingSchool->pivot->staff_id !== $user['id']) { // @phpstan-ignore-line
                        $existingUser->schools()
                            ->updateExistingPivot($school->id, ['staff_id' => $user['id']]);
                    }
                    // If there isn't a school relationship, add it here
                    elseif (! $existingSchool) {
                        $schoolUser->push([
                            'school_id' => $school->id,
                            'user_uuid' => $existingUser->uuid,
                            'staff_id' => $user['id'],
                        ]);
                    }

                    continue;
                }

                $uuid = UuidFactory::make();
                $newUsers->push([
                    'tenant_id' => $this->tenant->id,
                    'uuid' => $uuid,
                    'sis_id' => $user['users_dcid'],
                    'email' => $email,
                    'first_name' => $user['name']['first_name'] ?? null,
                    'last_name' => $user['name']['last_name'] ?? null,
                    'school_id' => $school->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $schoolUser->push([
                    'school_id' => $school->id,
                    'user_uuid' => $uuid,
                    'staff_id' => $user['id'],
                ]);
            }
        }

        DB::table('users')->insert($newUsers->toArray());
        DB::table('school_user')->insert($schoolUser->toArray());
    }

    public function syncSchoolStudents($sisId)
    {
        $school = $this->tenant->getSchoolFromSisId($sisId);
        $builder = $this->builder
            ->method('get')
            ->to("/ws/v1/school/{$school->sis_id}/student")
            ->q('school_enrollment.enroll_status==(A,P,G,T,H,I)')
            ->expansions('contact_info,school_enrollment,initial_enrollment');
        $newStudents = [];

        while ($results = $builder->paginate()) {
            $now = now()->format('Y-m-d H:i:s');

            // Get courses that exist already
            $existingStudents = $school->students()
                ->whereIn('sis_id', collect($results)->pluck('id'))
                ->get()
                ->keyBy('sis_id');

            $entries = array_reduce(
                $results->toArray(),
                function ($entries, $student) use ($school, $now, $existingStudents) {
                    $attributes = $this->getStudentAttributes($student);

                    // If it exists, then update
                    if ($existingStudent = $existingStudents->get($student['id'])) {
                        $existingStudent->update($attributes);

                        return $entries;
                    }

                    // It's a new student
                    $attributes['uuid'] = UuidFactory::make();
                    $attributes['tenant_id'] = $this->tenant->id;
                    $attributes['school_id'] = $school->id;
                    $attributes['sis_id'] = $student['id'];
                    $attributes['created_at'] = $now;
                    $attributes['updated_at'] = $now;
                    $entries[] = $attributes;

                    return $entries;
                },
                []
            );

            $newStudents = array_merge($newStudents, $entries);

            // Batch inserts by 500
            if (count($newStudents) % 500 === 0) {
                DB::table('students')->insert($newStudents);
                $newStudents = [];
            }
        }

        DB::table('students')->insert($newStudents);
    }

    public function syncStudent($sisId)
    {
        $student = $sisId instanceof Student
            ? $sisId
            : Student::where('sis_id', $sisId)->first();

        $id = $student
            ? $student->sis_id
            : $sisId;
        $response = $this->builder->to("/ws/v1/student/{$id}")
            ->expansions('contact_info,school_enrollment,initial_enrollment')
            ->get();

        $attributes = $this->getStudentAttributes($response->student);

        if ($student) {
            $student->update($attributes);

            return;
        }

        $school = School::where('sis_id', $response->school_enrollment->school_id)
            ->first();

        if (! $school) {
            return;
        }

        $now = now();
        $attributes['school_id'] = $school->id;
        $attributes['sis_id'] = $id;
        $attributes['created_at'] = $now;
        $attributes['updated_at'] = $now;

        $this->tenant->students()->create($attributes);
    }

    protected function getStudentAttributes($student)
    {
        $email = strtolower($student['contact_info']['email'] ?? '');

        return [
            'student_number' => $student['local_id'],
            'first_name' => Arr::get($student, 'name.first_name') ?? null,
            'last_name' => Arr::get($student, 'name.last_name') ?? null,
            'email' => $email ?: null,
            'grade_level' => Arr::get($student, 'school_enrollment.grade_level'),
            'enrolled' => Arr::get($student, 'school_enrollment.enroll_status_code') === 0,
            'enroll_status' => Arr::get($student, 'school_enrollment.enroll_status_code'),
            'current_entry_date' => Arr::get($student, 'school_enrollment.entry_date'),
            'current_exit_date' => Arr::get($student, 'school_enrollment.exit_date'),
            'initial_district_entry_date' => Arr::get($student, 'initial_enrollment.district_entry_date'),
            'initial_school_entry_date' => Arr::get($student, 'initial_enrollment.school_entry_date'),
            'initial_district_grade_level' => Arr::get($student, 'initial_enrollment.district_entry_grade_level'),
            'initial_school_grade_level' => Arr::get($student, 'initial_enrollment.school_entry_grade_level'),
        ];
    }

    public function syncSchoolCourses($sisId)
    {
        $school = $this->tenant->getSchoolFromSisId($sisId);
        $builder = $this->builder
            ->method('get')
            ->to("/ws/v1/school/{$school->sis_id}/course");
        // Get courses that exist already
        $existingCourses = $school->courses()
            ->get()
            ->keyBy('sis_id');
        $newCourses = [];

        try {
            while ($results = $builder->paginate()) {
                ray($results->count())->green();
                $now = now()->format('Y-m-d H:i:s');

                foreach ($results as $course) {
                    /** @var Course $existingCourse */
                    if ($existingCourse = $existingCourses->get($course->id)) {
                        $existingCourse->update([
                            'name' => $course->course_name,
                            'course_number' => $course->course_number,
                        ]);

                        continue;
                    }

                    // It's a new course
                    $newCourses[] = [
                        'tenant_id' => $this->tenant->id,
                        'school_id' => $school->id,
                        'name' => $course->course_name,
                        'course_number' => $course->course_number,
                        'sis_id' => $course->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (count($newCourses) % 500 === 0) {
                    DB::table('courses')->insert($newCourses);
                    $newCourses = [];
                }
            }

            DB::table('courses')->insert($newCourses);
        } catch (\Exception $exception) {
            Log::error("Failed importing courses for {$school->name}.", ['exception' => $exception]);
        }
    }

    public function syncSchoolTerms($sisId)
    {
        $school = $this->tenant->getSchoolFromSisId($sisId);

        $this->builder
            ->to("/ws/v1/school/{$school->sis_id}/term")
            ->get()
            ->collect()->each(function ($term) use ($school) {
                $school->terms()
                    ->updateOrCreate(
                        [
                            'tenant_id' => $this->tenant->id,
                            'school_id' => $school->id,
                            'sis_id' => $term['id'],
                        ],
                        [
                            'sis_assigned_id' => $term['local_id'],
                            'name' => $term['name'],
                            'abbreviation' => $term['abbreviation'],
                            'start_year' => $term['start_year'],
                            'portion' => $term['portion'],
                            'starts_at' => $term['start_date'],
                            'ends_at' => $term['end_date'],
                        ]
                    );
            });
    }

    public function syncSchoolStudentGuardians($sisId)
    {
        $school = $this->tenant->getSchoolFromSisId($sisId);

        /** @var RequestBuilder $builder */
        $builder = $this->builder
            ->method('post')
            ->withData(['dcid' => $school->sis_id])
            ->pq('com.archboard.sonarfms.school.contacts');
        $students = $school->students()
            ->pluck('uuid', 'sis_id');
        $users = $this->tenant->users()
            ->pluck('uuid', 'contact_id');
        $studentUser = [];
        $newUsers = [];
        $relations = [];

        while ($results = $builder->paginate()) {
            $now = now()->format('Y-m-d H:i:s');

            foreach ($results as $result) {
                // If the student doesn't exist
                // or the result doesn't have an email address/name
                // we can't do anything about the user record
                if (
                    ! isset($result['sis_id']) ||
                    ! $students->has($result['sis_id']) ||
                    ! isset($result['other_email']) ||
                    ! isset($result['first_name']) ||
                    ! isset($result['last_name'])
                ) {
                    continue;
                }

                $contactId = (int) $result['contact_id'];

                if (! $users->has($contactId)) {
                    $uuid = UuidFactory::make();
                    $users->put($contactId, $uuid);

                    $newUsers[] = [
                        'uuid' => $uuid,
                        'tenant_id' => $this->tenant->id,
                        'first_name' => $result['first_name'],
                        'last_name' => $result['last_name'],
                        // TODO not possible to get their account email, but may be ok ultimately
                        // since we can get their contact ids
                        'email' => $result['other_email'],
                        'contact_id' => $contactId,
                        'guardian_id' => $result['guardian_id'],
                        'school_id' => $school->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                $studentUuid = $students->get($result['sis_id']);
                $userUuid = $users->get($contactId);
                $key = $studentUuid.$userUuid;

                if (! isset($relations[$key])) {
                    $relations[$key] = 1;

                    $studentUser[] = [
                        'student_uuid' => $studentUuid,
                        'user_uuid' => $userUuid,
                        'relationship' => $result['relationship'],
                    ];
                }
            }
        }

        $this->persistSchoolStudentGuardians(
            $school,
            $newUsers,
            $studentUser
        );
    }

    /**
     * Add or update the valid relationships returned by PowerSchool. This
     * mirrors the per-student contact sync: an empty or partial response must
     * not remove relationships that can still be refreshed separately.
     */
    protected function persistSchoolStudentGuardians(
        School $school,
        array $newUsers,
        array $studentUsers
    ): void {
        DB::transaction(function () use ($newUsers, $studentUsers) {
            foreach (array_chunk($newUsers, 500) as $users) {
                DB::table('users')->insert($users);
            }

            foreach (array_chunk($studentUsers, 500) as $relations) {
                DB::table('student_user')->upsert(
                    $relations,
                    ['student_uuid', 'user_uuid'],
                    ['relationship']
                );
            }
        });

        // PowerSchool can return an empty or partial response. Heal access for
        // every relationship retained at this school, not only those present
        // in the current response. This is intentionally outside the database
        // transaction because Bouncer also invalidates its external cache.
        $school->students()
            ->select('students.uuid')
            ->with('users')
            ->chunk(100, function (Collection $students) {
                $students->each(function (Student $student) {
                    $student->grantGuardianViewPermissions($student->users);
                });
            });
    }

    /**
     * Syncs everything for a school:
     * staff, students, courses, sections, and enrollment
     *
     * @param  int|string  $sisId
     */
    public function fullSchoolSync($sisId)
    {
        $school = $this->tenant->getSchoolFromSisId($sisId);
        \Bouncer::scope()->to($school->id);
        \Bouncer::cache();

        ray()->newScreen("Sync for {$school->name}");
        ray()->measure();
        ray('syncing school info');
        $this->syncSchool($school);
        ray()->measure();
        ray('syncing school terms');
        $this->syncSchoolTerms($school);
        ray()->measure();
        ray('syncing school students');
        $this->syncSchoolStudents($school);
        ray()->measure();
        ray('syncing school guardians');
        $this->syncSchoolStudentGuardians($school);
        ray()->measure();
        ray('syncing school staff');
        $this->syncSchoolStaff($school);
        ray()->measure();

        // These are courses/sections/enrollments and aren't very relevant
        //        ray('syncing school courses');
        //        $this->syncSchoolCourses($school);
        //        ray()->measure();
        //        ray('syncing school sections');
        //        $this->syncSchoolSections($school);
        //        ray()->measure();
        //        ray('syncing school enrollment');
        //        $this->syncSchoolStudentEnrollment($school);
        //        ray()->measure();

        \Bouncer::refresh();
        event(new SchoolSyncComplete($school));
    }

    public function getBuilder(): RequestBuilder
    {
        return $this->builder;
    }

    public function getSisLabel(): string
    {
        return 'PowerSchool';
    }

    public function registerWebhooks()
    {
        $data = [
            'event_subscriptions' => [
                'key' => 'sonar-fms-key',
                'callback_url' => app()->environment('local')
                    ? 'https://archboard.us-2.sharedwithexpose.com/ps/webhook'
                    : url('/ps/webhook'),
                'event_subscription' => [
                    [
                        'resource' => '/ws/v1/student/*',
                        'event_type' => 'INSERT',
                    ],
                    [
                        'resource' => '/ws/v1/student/*',
                        'event_type' => 'UPDATE',
                    ],
                    [
                        'resource' => '/ws/v1/student/*',
                        'event_type' => 'DELETE',
                    ],
                    [
                        'resource' => '/ws/v1/student/*',
                        'event_type' => 'SCHOOL_ENROLLMENT',
                    ],
                    [
                        'resource' => '/ws/v1/section_enrollment/*',
                        'event_type' => 'INSERT',
                    ],
                    [
                        'resource' => '/ws/v1/section_enrollment/*',
                        'event_type' => 'UPDATE',
                    ],
                    [
                        'resource' => '/ws/v1/section_enrollment/*',
                        'event_type' => 'DELETE',
                    ],
                    [
                        'resource' => '/ws/v1/staff/*',
                        'event_type' => 'INSERT',
                    ],
                    [
                        'resource' => '/ws/v1/staff/*',
                        'event_type' => 'UPDATE',
                    ],
                    [
                        'resource' => '/ws/v1/staff/*',
                        'event_type' => 'DELETE',
                    ],
                    [
                        'resource' => '/ws/v1/section/*',
                        'event_type' => 'INSERT',
                    ],
                    [
                        'resource' => '/ws/v1/section/*',
                        'event_type' => 'UPDATE',
                    ],
                    [
                        'resource' => '/ws/v1/section/*',
                        'event_type' => 'DELETE',
                    ],
                    [
                        'resource' => '/ws/v1/course/*',
                        'event_type' => 'INSERT',
                    ],
                    [
                        'resource' => '/ws/v1/course/*',
                        'event_type' => 'UPDATE',
                    ],
                    [
                        'resource' => '/ws/v1/course/*',
                        'event_type' => 'DELETE',
                    ],
                    [
                        'resource' => '/ws/v1/test_subscription',
                        'event_type' => 'INSERT',
                    ],
                    [
                        'resource' => '/ws/v1/test_subscription',
                        'event_type' => 'UPDATE',
                    ],
                    [
                        'resource' => '/ws/v1/test_subscription',
                        'event_type' => 'DELETE',
                    ],
                ],
            ],
        ];

        $this->getBuilder()
            ->withData($data)
            ->to('/ws/v1/event_subscription')
            ->put();
    }
}
