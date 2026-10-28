<?php

namespace Tests\Feature;

use App\Jobs\SyncStudentContacts;
use App\Models\Invoice;
use App\Models\School;
use App\SisProviders\PowerSchoolProvider;
use GrantHolle\PowerSchool\Api\Facades\PowerSchool;
use GrantHolle\PowerSchool\Api\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SyncSchoolStudentGuardiansTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SyncStudentContacts::class]);
    }

    public function test_bulk_omission_rechecks_authoritative_contacts_and_revokes_removed_guardian(): void
    {
        $student = $this->createStudent();
        $guardian = $this->createUser(['contact_id' => 1001]);
        $student->users()->attach($guardian);
        $student->grantGuardianViewPermissions(collect([$guardian]));
        $invoice = new Invoice(['student_uuid' => $student->uuid]);
        $this->assertTrue($guardian->can('view', $student));
        $this->assertTrue($guardian->hasImplicitAccessToInvoice($invoice));

        $this->provider()->persist([], []);
        Bus::assertDispatched(SyncStudentContacts::class, fn ($job) => $job->student->is($student));
        $this->assertTrue($student->users()->whereKey($guardian->uuid)->exists());

        PowerSchool::shouldReceive('get')->once()->with("/ws/contacts/student/{$student->sis_id}")
            ->andReturn(new Response([], 'student'));
        (new SyncStudentContacts($student))->handle();

        $this->assertFalse($student->users()->whereKey($guardian->uuid)->exists());
        $this->assertFalse($guardian->fresh()->can('view', $student));
        $this->assertFalse($guardian->fresh()->hasImplicitAccessToInvoice($invoice));
    }

    public function test_authoritative_contact_without_email_keeps_its_existing_relationship(): void
    {
        $student = $this->createStudent();
        $guardian = $this->createUser(['contact_id' => 1001]);
        $student->users()->attach($guardian);
        PowerSchool::shouldReceive('get')->once()->with("/ws/contacts/student/{$student->sis_id}")
            ->andReturn(new Response([['contactId' => 1001, 'emails' => []]], 'student'));

        $student->syncContacts();

        $this->assertTrue($student->users()->whereKey($guardian->uuid)->exists());
    }

    public function test_contact_login_sync_revokes_global_student_permission_for_removed_relationship(): void
    {
        $student = $this->createStudent();
        $guardian = $this->createUser(['contact_id' => 1001]);
        $student->users()->attach($guardian);
        $student->grantGuardianViewPermissions(collect([$guardian]));
        $this->assertTrue($guardian->can('view', $student));
        PowerSchool::shouldReceive('get')->once()->with("/ws/contacts/{$guardian->contact_id}/students")
            ->andReturn(new Response([], 'student'));

        $guardian->syncStudents();

        $this->assertFalse($guardian->fresh()->can('view', $student));
        $this->assertFalse($guardian->students()->whereKey($student->uuid)->exists());
    }

    public function test_empty_bulk_response_preserves_existing_guardians()
    {
        $student = $this->createStudent();
        $guardian = $this->createUser(['contact_id' => 1001]);
        $student->users()->attach($guardian, ['relationship' => 'Mother']);

        $this->provider()->persist([], []);

        $this->assertDatabaseHas('student_user', [
            'student_uuid' => $student->uuid,
            'user_uuid' => $guardian->uuid,
            'relationship' => 'Mother',
        ]);
        $this->assertTrue($guardian->can('view', $student));
    }

    public function test_partial_bulk_response_adds_guardians_without_removing_existing_ones()
    {
        $syncedStudent = $this->createStudent();
        $omittedStudent = $this->createStudent();
        $oldGuardian = $this->createUser(['contact_id' => 1001]);
        $newGuardian = $this->createUser(['contact_id' => 1002]);

        $syncedStudent->users()->attach($oldGuardian, ['relationship' => 'Mother']);
        $omittedStudent->users()->attach($oldGuardian, ['relationship' => 'Mother']);

        $this->provider()->persist(
            [],
            [[
                'student_uuid' => $syncedStudent->uuid,
                'user_uuid' => $newGuardian->uuid,
                'relationship' => 'Father',
            ]]
        );

        $this->assertDatabaseHas('student_user', [
            'student_uuid' => $syncedStudent->uuid,
            'user_uuid' => $oldGuardian->uuid,
            'relationship' => 'Mother',
        ]);
        $this->assertDatabaseHas('student_user', [
            'student_uuid' => $syncedStudent->uuid,
            'user_uuid' => $newGuardian->uuid,
            'relationship' => 'Father',
        ]);
        $this->assertDatabaseHas('student_user', [
            'student_uuid' => $omittedStudent->uuid,
            'user_uuid' => $oldGuardian->uuid,
            'relationship' => 'Mother',
        ]);
        $this->assertTrue($oldGuardian->can('view', $syncedStudent));
        $this->assertTrue($oldGuardian->can('view', $omittedStudent));
        $this->assertTrue($newGuardian->can('view', $syncedStudent));
    }

    public function test_bulk_response_updates_an_existing_relationship_without_duplication()
    {
        $student = $this->createStudent();
        $guardian = $this->createUser(['contact_id' => 1001]);
        $student->users()->attach($guardian, ['relationship' => 'Mother']);

        $this->provider()->persist(
            [],
            [[
                'student_uuid' => $student->uuid,
                'user_uuid' => $guardian->uuid,
                'relationship' => 'Guardian',
            ]]
        );

        $this->assertDatabaseHas('student_user', [
            'student_uuid' => $student->uuid,
            'user_uuid' => $guardian->uuid,
            'relationship' => 'Guardian',
        ]);
        $this->assertDatabaseCount('student_user', 1);
        $this->assertTrue($guardian->can('view', $student));
    }

    public function test_per_student_contact_sync_grants_guardian_view_permission()
    {
        $student = $this->createStudent();
        $guardian = $this->createUser(['contact_id' => 1001]);

        $response = new Response(
            [[
                'emails' => [[
                    'primary' => true,
                    'address' => $guardian->email,
                ]],
                'firstName' => $guardian->first_name,
                'lastName' => $guardian->last_name,
                'contactId' => $guardian->contact_id,
                'contactStudents' => [[
                    'schoolNumber' => $this->school->school_number,
                    'guardianId' => 2001,
                    'studentDetails' => [[
                        'relationship' => 'Mother',
                    ]],
                ]],
            ]],
            'student'
        );

        PowerSchool::shouldReceive('get')
            ->once()
            ->with("/ws/contacts/student/{$student->sis_id}")
            ->andReturn($response);

        $student->syncContacts($this->tenant->schools->keyBy('school_number'));

        $this->assertDatabaseHas('student_user', [
            'student_uuid' => $student->uuid,
            'user_uuid' => $guardian->uuid,
            'relationship' => 'Mother',
        ]);
        $this->assertTrue($guardian->can('view', $student));
    }

    private function provider(): TestPowerSchoolProvider
    {
        return (new TestPowerSchoolProvider($this->tenant))
            ->forSchool($this->school);
    }
}

class TestPowerSchoolProvider extends PowerSchoolProvider
{
    private School $school;

    public function forSchool(School $school): static
    {
        $this->school = $school;

        return $this;
    }

    public function persist(array $users, array $relations): void
    {
        $this->persistSchoolStudentGuardians(
            $this->school,
            $users,
            $relations
        );
    }
}
