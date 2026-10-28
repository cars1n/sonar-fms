<?php

namespace App\Http\Controllers;

use App\Http\Requests\PortalNotificationRequest;
use App\Models\Invoice;
use App\Services\PortalPublisher;
use Illuminate\Http\RedirectResponse;

class PortalNotificationController extends Controller
{
    public function __invoke(PortalNotificationRequest $request, Invoice $invoice, PortalPublisher $publisher): RedirectResponse
    {
        abort_unless($invoice->school_id === $request->school()->id, 404);
        abort_unless($invoice->published_at && ! $invoice->parent_uuid, 422);
        abort_unless($publisher->enabledForSchool($invoice->school), 422, 'Guardian notifications are not enabled for this school.');
        if ($request->validated('action') === 'cancel') {
            $invoice->cancelNotification();
            $publisher->capture($invoice);
        } else {
            $invoice->update(['notify' => true, 'notify_at' => now()]);
            $publisher->capture($invoice, false, true);
        }

        return back();
    }
}
