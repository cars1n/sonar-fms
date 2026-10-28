<?php

namespace App\Services;

use App\Models\School;
use App\Models\Student;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PortalStudentIdentity
{
    public function upsert(School $school, array $data): Student
    {
        return DB::transaction(function () use ($school, $data): Student {
            School::whereKey($school->id)->lockForUpdate()->firstOrFail();
            $student = $school->students()->where('crm_reference', $data['crm_reference'])->first();
            $sisStudent = ! empty($data['sis_id'])
                ? $school->students()->where('sis_id', $data['sis_id'])->whereNull('merged_into_uuid')->first()
                : null;
            abort_if($student && $sisStudent && $student->uuid !== $sisStudent->uuid, 409, 'A PowerSchool record already exists. Reconcile the two student records.');
            abort_if($sisStudent && $sisStudent->crm_reference && $sisStudent->crm_reference !== $data['crm_reference'], 409, 'This PowerSchool student is linked to another CRM student.');
            abort_if($student && $student->sis_id && ! empty($data['sis_id']) && (int) $student->sis_id !== (int) $data['sis_id'], 409, 'The existing PowerSchool identity cannot be replaced.');
            $student = $student ?? $sisStudent ?? new Student([
                'tenant_id' => $school->tenant_id, 'school_id' => $school->id,
                'enrolled' => false, 'sis_id' => null,
            ]);
            if (! $student->sis_id) {
                $student->fill(Arr::only($data, ['first_name', 'last_name', 'grade_level']));
            }
            $student->crm_reference = $data['crm_reference'];
            $student->application_reference = $data['application_reference'] ?? null;
            $student->sis_id = $data['sis_id'] ?? $student->sis_id;
            $student->save();

            return $student;
        });
    }

    public function fromSis(School $school, int $sisId, array $attributes): Student
    {
        return DB::transaction(function () use ($school, $sisId, $attributes): Student {
            School::whereKey($school->id)->lockForUpdate()->firstOrFail();
            $student = $school->students()->where('sis_id', $sisId)->whereNull('merged_into_uuid')->first();
            if (! $student && app(PortalPublisher::class)->enabledForSchool($school)) {
                $url = config('services.crm_portal.identity_url');
                if (! $url || ! config('services.crm_portal.webhook_secret')) {
                    throw new \RuntimeException('Configure the CRM identity endpoint before importing new PowerSchool students.');
                }
                $identity = Http::withToken(config('services.crm_portal.webhook_secret'))
                    ->connectTimeout(3)->timeout(15)->get($url, ['school_sis_id' => $school->sis_id, 'sis_id' => $sisId])->throw()->json('data');
                if ($identity) {
                    $student = $this->upsert($school, $attributes + ['sis_id' => $sisId, 'crm_reference' => $identity['crm_reference']]);
                }
            }
            $student ??= new Student(['tenant_id' => $school->tenant_id, 'school_id' => $school->id, 'sis_id' => $sisId]);
            $student->fill($attributes)->save();

            return $student;
        });
    }

    public function reconcile(School $school, string $reference, string $duplicateUuid): Student
    {
        [$student, $duplicate, $guardians] = DB::transaction(function () use ($school, $reference, $duplicateUuid): array {
            School::whereKey($school->id)->lockForUpdate()->firstOrFail();
            $canonical = $school->students()->where('crm_reference', $reference)->firstOrFail();
            $duplicate = $school->students()->whereKey($duplicateUuid)->firstOrFail();
            abort_if($canonical->is($duplicate) || $duplicate->merged_into_uuid || $duplicate->crm_reference || ! $duplicate->sis_id, 409);
            abort_if($canonical->sis_id && $canonical->sis_id !== $duplicate->sis_id, 409);
            $canonical->sis_id = $duplicate->sis_id;
            $canonical->enrolled = $duplicate->enrolled;
            $canonical->student_number = $duplicate->student_number;
            $canonical->save();
            $school->invoices()->where('student_uuid', $duplicate->uuid)->update(['student_uuid' => $canonical->uuid]);
            $guardians = $duplicate->users()->get();
            $canonical->users()->syncWithoutDetaching($guardians->modelKeys());
            $duplicate->users()->detach();
            $duplicate->update(['sis_id' => null, 'merged_into_uuid' => $canonical->uuid]);
            $canonical->setAccountBalance()->setRevenue()->save();
            activity()->on($canonical)->withProperties(['archived_student_uuid' => $duplicateUuid])
                ->log('Confirmed CRM student identity reconciliation; invoices retained.');

            return [$canonical, $duplicate, $guardians];
        });

        $student->grantGuardianViewPermissions($student->users()->get());
        $duplicate->revokeGuardianViewPermissions($guardians);

        return $student;
    }
}
