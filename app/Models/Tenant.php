<?php

namespace App\Models;

use App\Jobs\SyncSchool;
use App\Notifications\TenantSyncComplete;
use App\Notifications\TenantSyncFailed;
use App\SisProviders\SisProvider;
use App\Traits\HasResource;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Spatie\Multitenancy\Models\Tenant as TenantBase;

/**
 * @mixin IdeHelperTenant
 */
class Tenant extends TenantBase
{
    use HasFactory;
    use HasResource;

    protected $guarded = [];

    protected $casts = [
        'allow_password_auth' => 'boolean',
        'allow_oidc_login' => 'boolean',
    ];

    protected static function booted()
    {
        if (app()->environment('testing')) {
            static::created(function (Tenant $tenant) {
                $tenant->schools()
                    ->saveMany(School::factory()->count(3)->make(['active' => true]));
            });
        }
    }

    public function schools(): HasMany
    {
        return $this->hasMany(School::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function syncTimes(): HasMany
    {
        return $this->hasMany(SyncTime::class)->orderBy('hour');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function feeCategories(): HasMany
    {
        return $this->hasMany(FeeCategory::class);
    }

    public function getSisAttribute(): string
    {
        return $this->sisProvider()->getSisLabel();
    }

    public function getSyncNotificationEmails(): array
    {
        if (! $this->sync_notification_emails) {
            return [];
        }

        // Split by comma, semi-colon, pipe or space
        $emails = preg_split('/([,;| ])/', $this->sync_notification_emails);

        return array_reduce($emails, function ($emails, $email) {
            $email = trim($email);
            $validator = Validator::make(compact('email'), ['email' => 'required|email']);

            if ($validator->valid()) {
                $emails[] = $email;
            }

            return $emails;
        }, []);
    }

    public function notifySyncEmails(string $notification)
    {
        collect($this->getSyncNotificationEmails())
            ->each(function ($email) use ($notification) {
                ray("Notifying {$email}", $notification);

                Notification::route('mail', $email)
                    ->notify(new $notification);
            });
    }

    public function sisProvider(): SisProvider
    {
        return new $this->sis_provider($this);
    }

    public function getSchoolFromSisId($sisId): School
    {
        if ($sisId instanceof School) {
            return $sisId;
        }

        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->schools()->where('sis_id', $sisId)->firstOrFail();
    }

    public function hasBeenInstalled(): bool
    {
        return $this->id &&
            $this->schools()->exists() &&
            $this->users()->exists();
    }

    /**
     * This syncs all the schools' basic info
     * Then syncs only the active schools' SIS data
     * terms, students, teachers, courses, sections, and enrollment
     *
     * @return $this
     *
     * @throws \Throwable
     */
    public function startSisSync(): static
    {
        ray()->clearScreen();
        ray('Starting sis sync');

        $schools = $this->sisProvider()
            ->syncSchools()
            ->filter(fn (School $school) => $school->active);

        if ($schools->isEmpty()) {
            return $this;
        }

        $batch = Bus::batch(
            $schools->map(fn (School $school) => new SyncSchool($school))
        )->then(function (Batch $batch) {
            $this->notifySyncEmails(TenantSyncComplete::class);
        })->catch(function (Batch $batch, \Throwable $ex) {
            $this->notifySyncEmails(TenantSyncFailed::class);
        })->finally(function (Batch $batch) {
            ray("Batch {$batch->id} has finished");
            $this->refresh()->update(['batch_id' => null]);
        })->name('Tenant SIS Sync')->dispatch();

        $this->update(['batch_id' => $batch->id]);
        ray("Batch {$batch->id} has been dispatched");

        return $this;
    }

    public function toArray()
    {
        // TODO for cloud, these SIS specific settings need to be pulled from the provider
        return [
            'name' => $this->name,
            'license' => $this->license,
            'sis' => $this->sis,
            'ps_url' => $this->ps_url,
            'ps_client_id' => $this->ps_client_id,
            'ps_secret' => $this->ps_secret,
            'allow_password_auth' => $this->allow_password_auth,
            'allow_oidc_login' => $this->allow_oidc_login,
            'sync_notification_emails' => $this->sync_notification_emails,
            'smtp_host' => $this->smtp_host,
            'smtp_port' => $this->smtp_port,
            'smtp_username' => $this->smtp_username,
            'smtp_password' => $this->smtp_password,
            'smtp_from_name' => $this->smtp_from_name,
            'smtp_from_address' => $this->smtp_from_address,
            'smtp_encryption' => $this->smtp_encryption,
            'is_syncing' => (bool) $this->batch_id,
            'is_cloud' => config('app.cloud'),
        ];
    }
}
