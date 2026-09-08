<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortalEvent;
use App\Models\PortalInvoiceState;
use App\Models\School;
use App\Services\PortalPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $school = School::where('sis_id', $request->integer('school_sis_id'))->firstOrFail();
        $events = PortalEvent::where('school_id', $school->id)->where('id', '>', $request->integer('after'))
            ->orderBy('id')->limit(100)->get();

        return response()->json(['data' => $events->map(fn ($event) => ['id' => $event->id, 'data' => $event->payload])]);
    }

    public function show(Request $request, string $invoice, PortalPublisher $publisher): JsonResponse
    {
        $school = School::where('sis_id', $request->integer('school_sis_id'))->firstOrFail();
        $model = $school->invoices()->whereKey($invoice)->firstOrFail();
        $publisher->capture($model);
        $state = PortalInvoiceState::find($invoice);

        return response()->json(['data' => $publisher->snapshot($model) + [
            'request_id' => $state?->request_id, 'send_at' => $state?->send_at?->toIso8601String(),
        ]]);
    }
}
