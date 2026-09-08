<?php

use App\Http\Controllers\Api\PortalInvoiceController;
use App\Http\Middleware\Installed;
use Illuminate\Support\Facades\Route;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
 */

Route::middleware([Installed::class, NeedsTenant::class, 'crm_portal'])
    ->prefix('portal/v1')
    ->name('api.portal.')
    ->group(function () {
        Route::get('/invoices', [PortalInvoiceController::class, 'index'])
            ->name('invoices.index');
        Route::get('/invoices/{invoice}', [PortalInvoiceController::class, 'show'])
            ->name('invoices.show');
        Route::get('/invoices/{invoice}/pdf', [PortalInvoiceController::class, 'download'])
            ->name('invoices.download');
    });

Route::middleware([Installed::class, NeedsTenant::class, 'crm_portal'])->prefix('portal/v2')->group(function (): void {
    Route::put('students/{reference}', [\App\Http\Controllers\Api\PortalStudentController::class, 'update']);
    Route::post('students/reconcile', [\App\Http\Controllers\Api\PortalStudentController::class, 'reconcile']);
    Route::get('events', [\App\Http\Controllers\Api\PortalEventController::class, 'index']);
    Route::get('events/invoices/{invoice}', [\App\Http\Controllers\Api\PortalEventController::class, 'show']);
    Route::match(['get', 'post'], 'invoices', [\App\Http\Controllers\Api\PortalInvoiceV2Controller::class, 'index']);
    Route::match(['get', 'post'], 'invoices/{invoice}', [\App\Http\Controllers\Api\PortalInvoiceV2Controller::class, 'show']);
    Route::match(['get', 'post'], 'invoices/{invoice}/pdf', [\App\Http\Controllers\Api\PortalInvoiceV2Controller::class, 'download']);
});
