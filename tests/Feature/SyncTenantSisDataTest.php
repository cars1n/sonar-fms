<?php

namespace Tests\Feature;

use App\Jobs\SyncSchool;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\PendingBatchFake;
use Tests\Fakes\OfflineSchoolsProvider;
use Tests\TestCase;

class SyncTenantSisDataTest extends TestCase
{
    use RefreshDatabase;

    protected bool $signIn = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant->update(['sis_provider' => OfflineSchoolsProvider::class]);
    }

    protected function seedSyncTimes($count = 3)
    {
        for ($i = 0; $i < $count; $i++) {
            $this->tenant->syncTimes()
                ->create(['hour' => $i]);
        }
    }

    public function test_cant_update_sync_times()
    {
        $this->get(route('sync-times.index'))
            ->assertForbidden();
    }

    public function test_can_retrieve_sync_times()
    {
        $this->manageTenancy();
        $this->seedSyncTimes();

        $res = $this->getJson(route('sync-times.index'))
            ->assertOk();
        $json = $res->json();

        $this->assertCount(3, $json);
    }

    public function test_cannot_save_without_permission()
    {
        $this->post(route('sync-times.store'), ['hour' => 0])
            ->assertForbidden();
    }

    public function test_can_create_with_permission()
    {
        $this->manageTenancy();
        $this->post(route('sync-times.store'), ['hour' => 0])
            ->assertSessionHas('success')
            ->assertRedirect();

        $this->assertDatabaseHas('sync_times', ['tenant_id' => $this->tenant->id, 'hour' => 0]);
        $this->assertEquals(1, $this->tenant->syncTimes->count());
    }

    public function test_can_delete_existing_sync_time()
    {
        $this->manageTenancy();
        $this->seedSyncTimes(1);
        $syncTime = $this->tenant->syncTimes()->first();

        $this->delete(route('sync-times.destroy', $syncTime))
            ->assertSessionHas('success')
            ->assertRedirect();

        $this->assertEquals(0, $this->tenant->syncTimes()->count());
    }

    public function test_can_save_sync_emails()
    {
        $this->manageTenancy();

        $data = [
            'sync_notification_emails' => 'email1@example.com,email2@example.com',
        ];

        $this->put(route('sis.sync.emails'), $data)
            ->assertSessionHas('success')
            ->assertRedirect();

        $this->tenant->refresh();
        $this->assertEquals($data['sync_notification_emails'], $this->tenant->sync_notification_emails);
    }

    public function test_will_do_nothing_without_active_schools()
    {
        $this->withoutExceptionHandling();
        $this->manageTenancy();

        Queue::fake();
        Bus::fake();
        $this->tenant->schools()->delete();

        $this->post(route('sis.sync'))
            ->assertSessionHas('success')
            ->assertRedirect();

        Queue::assertNothingPushed();
        Bus::assertNotDispatched(SyncSchool::class);
    }

    public function test_will_dispatch_jobs_for_active_schools()
    {
        $this->withoutExceptionHandling();
        $this->manageTenancy();
        $activeSchoolCount = 2;

        $this->tenant->schools()->delete();
        $this->tenant->sisProvider()
            ->syncSchools();

        $this->assertTrue($this->tenant->schools->isNotEmpty());

        $schools = $this->tenant->schools()->get();
        $activeSchools = $schools->random($activeSchoolCount);
        School::whereIn('id', $activeSchools->pluck('id'))
            ->update(['active' => true]);

        $this->assertEquals(2, $this->tenant->schools()->active()->count());

        Queue::fake();
        Bus::fake();

        $this->post(route('sis.sync'))
            ->assertSessionHas('success')
            ->assertRedirect();

        Bus::assertBatched(function (PendingBatchFake $batch) use ($activeSchools) {
            return $batch->jobs->count() === $activeSchools->count() &&
                $batch->jobs->every(function (SyncSchool $job) use ($activeSchools) {
                    return $activeSchools->contains('id', $job->school->id);
                });
        });
    }
}
