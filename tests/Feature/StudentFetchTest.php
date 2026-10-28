<?php

namespace Tests\Feature;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\SignsIn;

class StudentFetchTest extends TestCase
{
    use RefreshDatabase;
    use SignsIn;

    public function test_permissions_work()
    {
        $this->get(route('students.search', ['s' => '']))
            ->assertForbidden();
    }

    public function test_can_fetch_students_based_on_search()
    {
        $this->assignPermission('view', Student::class);

        $this->createStudent(['first_name' => 'ASDFASDF']);

        $res = $this->get(route('students.search', ['s' => 'asdf']))
            ->assertOk();
        $results = $res->json();

        $this->assertCount(1, $results);
    }

    public function test_only_get_10_results()
    {
        $this->assignPermission('view', Student::class);

        $this->createStudents(['first_name' => 'UniqueSearchFixture'], 11);

        $res = $this->get(route('students.search', ['s' => 'UNIQUESEARCHFIXTURE']))
            ->assertOk();

        $results = $res->json();

        $this->assertCount(10, $results);
        $this->assertTrue(collect($results)->every(fn ($s) => $s['first_name'] === 'UniqueSearchFixture'));
    }
}
