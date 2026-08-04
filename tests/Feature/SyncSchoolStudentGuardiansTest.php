<?php

namespace Tests\Feature;

use App\Models\School;
use App\SisProviders\PowerSchoolProvider;
use GrantHolle\PowerSchool\Api\Facades\PowerSchool;
use GrantHolle\PowerSchool\Api\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncSchoolStudentGuardiansTest extends TestCase
{
    use RefreshDatabase;

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
