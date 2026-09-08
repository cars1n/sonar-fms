<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\PortalStudentIdentity;
use Illuminate\Http\JsonResponse;

class PortalStudentController extends Controller
{
    public function update(\App\Http\Requests\PortalStudentRequest $request, string $reference, PortalStudentIdentity $identity): JsonResponse
    {
        $data = $request->validated();
        abort_unless($reference === $data['crm_reference'], 422);
        $school = School::where('sis_id', $data['school_sis_id'])->firstOrFail();
        $student = $identity->upsert($school, $data);

        return response()->json(['data' => ['uuid' => $student->uuid, 'crm_reference' => $student->crm_reference]]);
    }

    public function reconcile(\App\Http\Requests\PortalReconcileRequest $request, PortalStudentIdentity $identity): JsonResponse
    {
        $data = $request->validated();
        $school = School::where('sis_id', $data['school_sis_id'])->firstOrFail();
        $student = $identity->reconcile($school, $data['crm_reference'], $data['duplicate_uuid']);

        return response()->json(['data' => ['uuid' => $student->uuid]]);
    }
}
