<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

/**
 * Self-hosted only routes
 */
Route::middleware(['self_hosted', 'can_install'])->group(function () {
    Route::get('/install', \App\Http\Controllers\ShowInstallationPageController::class);
    Route::post('/install', \App\Http\Controllers\CreateTenantController::class)
        ->name('install');
});

Route::post('/ps/webhook', \App\Http\Controllers\PowerSchoolWebhookController::class);

Route::redirect('/.well-known/change-password', '/settings/personal');

Route::middleware('tenant')->group(function () {
    Route::get('/', \App\Http\Controllers\IndexController::class);

    // PowerSchool OpenID 2.0 auth
    Route::get('/auth/powerschool/openid', [\App\Http\Controllers\Auth\PowerSchoolOpenIdLoginController::class, 'authenticate']);
    Route::get('/auth/powerschool/openid/verify', [\App\Http\Controllers\Auth\PowerSchoolOpenIdLoginController::class, 'login'])
        ->name('openid.verify');

    // PowerSchool OpenID Connect
    Route::get('/auth/powerschool/oidc/login', [\App\Http\Controllers\Auth\PowerSchoolOidcController::class, 'authenticate'])
        ->name('oidc.login');
    Route::middleware('allows_oidc_auth')
        ->get('/auth/powerschool/oidc', [\App\Http\Controllers\Auth\PowerSchoolOidcController::class, 'login']);

    // Normal auth
    require __DIR__.'/auth.php';

    Route::middleware('auth')
        ->group(function () {
            // When the user is missing a school
            Route::get('/select-school', [\App\Http\Controllers\MissingSchoolController::class, 'index'])
                ->name('missing-school');
            Route::put('/select-school', [\App\Http\Controllers\MissingSchoolController::class, 'update']);
        });

    Route::middleware(['auth', 'has_school'])->group(function () {
        Route::post('/invoices/{invoice}/portal-notification', \App\Http\Controllers\PortalNotificationController::class)->name('invoices.portal-notification');
        Route::get('/ping', \App\Http\Controllers\CheckAuthStatusController::class)
            ->name('auth.status');

        Route::get('/csrf-token', \App\Http\Controllers\RefreshCsrfTokenController::class)
            ->name('csrf-token');

        Route::get('/timezones', \App\Http\Controllers\GetTimezonesController::class)
            ->name('timezones');

        Route::get('/search', \App\Http\Controllers\GlobalSearchController::class)
            ->name('search');

        Route::get('/home', \App\Http\Controllers\HomeController::class)
            ->name('home');

        Route::put('/change-schools', \App\Http\Controllers\ChangeSchoolController::class)
            ->name('schools.change');

        Route::post('/locale', \App\Http\Controllers\ChangeLocaleController::class)
            ->name('locale');

        Route::post('/preview-template', \App\Http\Controllers\PreviewTemplateController::class)
            ->name('preview.template');

        Route::get('/my-students', [\App\Http\Controllers\MyStudentController::class, 'index'])
            ->name('my-students.index');

        Route::get('/my-students/{student}', [\App\Http\Controllers\MyStudentController::class, 'show'])
            ->name('my-students.show');

        Route::get('/my-invoices', [\App\Http\Controllers\MyInvoiceController::class, 'index'])
            ->name('my-invoices.index');

        Route::get('/my-invoices/{invoice}', [\App\Http\Controllers\MyInvoiceController::class, 'show'])
            ->name('my-invoices.show');

        Route::middleware('school_settings')
            ->group(function () {
                /**
                 * Student-related routes
                 */
                Route::get('/students', [\App\Http\Controllers\StudentController::class, 'index'])
                    ->name('students.index');

                Route::get('/search/students', \App\Http\Controllers\StudentFetchController::class)
                    ->name('students.search');
                Route::post('/search/students', \App\Http\Controllers\StudentFetchController::class);
                Route::post('/search/families', \App\Http\Controllers\SearchFamiliesController::class);

                Route::prefix('/students/{student}')
                    ->name('students.')
                    ->group(function () {
                        Route::get('/', [\App\Http\Controllers\StudentController::class, 'show'])
                            ->name('show');

                        Route::post('/guardians/sync', \App\Http\Controllers\SyncStudentGuardiansController::class)
                            ->name('guardians.sync');

                        Route::resource('/invoices', \App\Http\Controllers\Students\StudentInvoiceController::class);

                        Route::middleware('can:comment,student')
                            ->resource('/comments', \App\Http\Controllers\StudentCommentController::class)
                            ->except('create', 'edit');

                        Route::resource('/tags', \App\Http\Controllers\StudentTagController::class)
                            ->only('index', 'store');

                        Route::put('/balance', \App\Http\Controllers\SetStudentAccountBalanceController::class)
                            ->name('balance');

                        Route::put('/revenue', \App\Http\Controllers\SetStudentRevenueController::class)
                            ->name('revenue');
                    });

                /**
                 * Families
                 */
                Route::post('/families', [\App\Http\Controllers\FamilyController::class, 'store']);
                Route::get('/families/{family}', [\App\Http\Controllers\FamilyController::class, 'show']);
                Route::post('/families/{family}/students/{student}', [\App\Http\Controllers\FamilyStudentController::class, 'store']);
                Route::delete('/families/{family}/students/{student}', [\App\Http\Controllers\FamilyStudentController::class, 'destroy']);

                /**
                 * Selections
                 */
                Route::put('/student-selection/balances', \App\Http\Controllers\UpdateStudentSelectionBalancesController::class)
                    ->name('student-selection.balances');

                Route::resource('/student-selection', \App\Http\Controllers\StudentSelectionController::class)
                    ->except('create', 'show', 'edit');

                Route::delete('/student-selection', \App\Http\Controllers\RemoveStudentSelectionController::class)
                    ->name('student-selection.remove');

                Route::put('/invoice-selection/publish', \App\Http\Controllers\PublishSelectedInvoicesController::class)
                    ->name('invoice-selection.publish');

                Route::post('/invoice-selection/pdf', \App\Http\Controllers\GeneratePdfForSelectionController::class)
                    ->name('invoice-selection.pdf');

                Route::resource('/invoice-selection', \App\Http\Controllers\InvoiceSelectionController::class)
                    ->except('create', 'show', 'edit');

                Route::delete('/invoice-selection', \App\Http\Controllers\RemoveInvoiceSelectionController::class)
                    ->name('invoice-selection.remove');

                Route::get('/invoice-selection/published', \App\Http\Controllers\CheckPublishedStatusController::class)
                    ->name('invoice-selection.published');

                /**
                 * Fee-related routes
                 */
                Route::resource('/departments', \App\Http\Controllers\DepartmentController::class)
                    ->except('create', 'edit');

                Route::resource('/fee-categories', \App\Http\Controllers\FeeCategoryController::class)
                    ->except('create', 'edit');

                Route::get('/fees/all', \App\Http\Controllers\GetFeesController::class)
                    ->name('fees.all');

                Route::resource('/fees', \App\Http\Controllers\FeeController::class)
                    ->except('create', 'edit');

                /**
                 * Scholarships
                 */
                Route::get('/resolution-strategies', \App\Http\Controllers\GetResolutionStrategies::class)
                    ->name('resolution-strategies.all');

                Route::get('/scholarships/all', \App\Http\Controllers\GetScholarshipsController::class)
                    ->name('scholarships.all');

                Route::resource('/scholarships', \App\Http\Controllers\ScholarshipController::class)
                    ->except('create', 'edit');

                /**
                 * Invoice imports
                 */
                Route::name('invoices')
                    ->resource('/invoices/imports', \App\Http\Controllers\InvoiceImportController::class);

                Route::prefix('/invoices/imports/{import}')
                    ->name('invoices.imports.')
                    ->group(function () {
                        Route::get('/map', [\App\Http\Controllers\MapInvoiceImportController::class, 'index'])
                            ->name('map');
                        Route::put('/map', [\App\Http\Controllers\MapInvoiceImportController::class, 'update']);

                        Route::get('/preview', \App\Http\Controllers\PreviewInvoiceImportController::class)
                            ->name('preview');

                        Route::post('/start', \App\Http\Controllers\StartInvoiceImport::class)
                            ->name('start');

                        Route::post('/reverse', \App\Http\Controllers\RollBackInvoiceImportController::class)
                            ->name('rollback');

                        Route::post('/template', \App\Http\Controllers\ConvertInvoiceImportMappingToTemplateController::class)
                            ->name('template');

                        Route::get('/download', \App\Http\Controllers\DownloadInvoiceImportFileController::class)
                            ->name('download');
                    });

                /**
                 * Invoices
                 */
                Route::get('/invoices', [\App\Http\Controllers\InvoiceController::class, 'index'])
                    ->name('invoices.index');
                Route::post('/invoices', [\App\Http\Controllers\InvoiceController::class, 'store'])
                    ->name('invoices.store');

                Route::post('/invoices/draft', \App\Http\Controllers\SaveInvoiceAsDraftController::class)
                    ->name('invoices.store.draft');

                Route::get('/search/invoices', \App\Http\Controllers\InvoiceSearchController::class)
                    ->name('invoices.search');
                Route::post('/search/invoices', \App\Http\Controllers\InvoiceSearchController::class);

                Route::middleware('invoice_unpublished')
                    ->put('/invoices/draft/{invoice}', \App\Http\Controllers\UpdateDraftInvoiceController::class)
                    ->name('invoices.update.draft');

                Route::get('/batches/{batch}/edit', \App\Http\Controllers\EditBatchDraftInvoicesController::class)
                    ->name('batches.edit');

                Route::post('/batches/{batch}/draft', \App\Http\Controllers\UpdateBatchDraftController::class)
                    ->name('batches.draft');

                Route::put('/batches/{batch}', \App\Http\Controllers\UpdateBatchController::class)
                    ->name('batches.update');

                Route::get('create', [\App\Http\Controllers\InvoiceController::class, 'create'])
                    ->name('invoices.create');

                Route::get('/invoices/create', [\App\Http\Controllers\InvoiceController::class, 'create']);

                Route::prefix('/invoices/{invoice}')
                    ->name('invoices.')
                    ->group(function () {
                        Route::get('/', [\App\Http\Controllers\InvoiceController::class, 'show'])
                            ->name('show');

                        Route::middleware('invoice_unpublished')
                            ->group(function () {
                                Route::get('edit', [\App\Http\Controllers\InvoiceController::class, 'edit'])
                                    ->name('edit');

                                Route::put('update', [\App\Http\Controllers\InvoiceController::class, 'update'])
                                    ->name('update');
                            });

                        Route::post('status', \App\Http\Controllers\ChangeInvoiceStatusController::class)
                            ->name('status');

                        Route::get('duplicate', \App\Http\Controllers\DuplicateInvoiceController::class)
                            ->name('duplicate');

                        Route::post('convert', \App\Http\Controllers\ConvertInvoiceToTemplateController::class)
                            ->name('convert');

                        Route::put('publish', \App\Http\Controllers\PublishInvoiceController::class)
                            ->name('publish');

                        Route::put('calculate', \App\Http\Controllers\RecacheInvoiceController::class)
                            ->name('calculate');

                        Route::post('distribute', \App\Http\Controllers\DistributeInvoicePaymentsController::class)
                            ->name('distribute');

                        Route::get('payments', \App\Http\Controllers\FetchInvoicePaymentsController::class)
                            ->name('payments');

                        Route::get('payments/related', \App\Http\Controllers\FetchRelatedPaymentsController::class)
                            ->name('payments.related');

                        Route::get('preview', \App\Http\Controllers\PreviewInvoiceController::class)
                            ->name('preview');

                        Route::get('pdf', \App\Http\Controllers\DownloadInvoicePdfController::class)
                            ->name('download');

                        Route::resource('refunds', \App\Http\Controllers\InvoiceRefundController::class)
                            ->names('refunds')
                            ->except('edit', 'update', 'destroy');

                        Route::get('related-refunds', \App\Http\Controllers\FetchRelatedRefundsController::class)
                            ->name('refunds.related');
                    });

                /**
                 * Payments and payment imports
                 */
                Route::resource('/payments/imports/templates', \App\Http\Controllers\PaymentImportTemplateController::class)
                    ->except(['create', 'edit'])
                    ->names('payments.imports.templates');

                Route::prefix('/payments/imports/{import}')
                    ->name('payments.imports.')
                    ->group(function () {
                        Route::get('/preview', \App\Http\Controllers\PreviewPaymentImportController::class)
                            ->name('preview');

                        Route::get('/download', \App\Http\Controllers\DownloadPaymentImportFileController::class)
                            ->name('download');

                        Route::get('/map', [\App\Http\Controllers\MapPaymentImportController::class, 'index'])
                            ->name('map');
                        Route::put('/map', [\App\Http\Controllers\MapPaymentImportController::class, 'update']);

                        Route::post('/start', \App\Http\Controllers\StartPaymentImportController::class)
                            ->name('start');

                        Route::post('/reverse', \App\Http\Controllers\RollBackPaymentImportController::class)
                            ->name('rollback');

                        Route::post('/template', \App\Http\Controllers\ConvertPaymentImportMappingToTemplateController::class)
                            ->name('template');
                    });

                Route::resource('/payments/imports', \App\Http\Controllers\PaymentImportController::class)
                    ->names('payments.imports');

                Route::resource('/payments', \App\Http\Controllers\InvoicePaymentController::class);

                /**
                 * Other stuff
                 */
                Route::resource('/templates', \App\Http\Controllers\InvoiceTemplateController::class)
                    ->except('create', 'edit');

                Route::get('/selection/invoices/create', [\App\Http\Controllers\StudentSelectionInvoiceController::class, 'index'])
                    ->name('selection.invoices.create');
                Route::post('/selection/invoices/create', [\App\Http\Controllers\StudentSelectionInvoiceController::class, 'store'])
                    ->name('selection.invoices.store');

                Route::get('/terms', [\App\Http\Controllers\TermController::class, 'index'])
                    ->name('terms.index');

                Route::resource('/layouts/receipts', \App\Http\Controllers\ReceiptLayoutController::class)
                    ->parameters([
                        'receipts' => 'layout',
                    ])
                    ->names('receipt-layouts');

                Route::post('/layouts/receipts/{layout}/default', \App\Http\Controllers\MakeReceiptLayoutDefault::class)
                    ->name('receipt-layouts.default');

                Route::middleware([])
                    ->group(function () {
                        Route::get('/payments/{payment}/receipt', \App\Http\Controllers\PaymentReceiptController::class)
                            ->name('payments.receipt');

                        Route::get('/layouts/receipts/{layout}/preview', \App\Http\Controllers\PreviewReceiptLayoutController::class)
                            ->name('receipt-layouts.preview');

                        Route::get('/receipts/{receipt}', [\App\Http\Controllers\ReceiptController::class, 'show'])
                            ->name('receipts.show');
                    });

                Route::delete('/receipts/{receipt}', [\App\Http\Controllers\ReceiptController::class, 'destroy'])
                    ->name('receipts.destroy');

                Route::get('/invoices/{invoice}/receipts', \App\Http\Controllers\InvoiceReceiptController::class)
                    ->name('invoices.receipts');

                Route::get('/payments/{payment}/receipts', \App\Http\Controllers\FetchPaymentReceiptsController::class)
                    ->name('payments.receipts');

                Route::resource('/layouts/invoices', \App\Http\Controllers\InvoiceLayoutController::class)
                    ->parameters([
                        'invoices' => 'layout',
                    ])
                    ->names('layouts');

                Route::post('/layouts/invoices/{layout}/default', \App\Http\Controllers\MakeInvoiceLayoutDefault::class)
                    ->name('layouts.default');

                Route::get('/layouts/invoices/{layout}/preview', \App\Http\Controllers\PreviewLayoutController::class)
                    ->name('layouts.preview');

                Route::get('/combine', [\App\Http\Controllers\CombineInvoiceController::class, 'index'])
                    ->name('invoices.combine');
                Route::post('/combine', [\App\Http\Controllers\CombineInvoiceController::class, 'store']);
                Route::get('/combine/{invoice}', [\App\Http\Controllers\CombineInvoiceController::class, 'edit']);
                Route::put('/combine/{invoice}', [\App\Http\Controllers\CombineInvoiceController::class, 'update']);

                Route::delete('/child/{invoice}', \App\Http\Controllers\RemoveChildInvoiceController::class);

                Route::get('/tags/students', \App\Http\Controllers\FetchStudentTagsController::class);

                /**
                 * User Routes
                 */
                Route::resource('/users', \App\Http\Controllers\UserController::class)
                    ->except('create');

                Route::get('/users/{user}/permissions', \App\Http\Controllers\GetUserPermissionsController::class)
                    ->name('users.permissions');
                Route::put('/users/{user}/permissions', \App\Http\Controllers\Settings\UpdateUserPermissions::class);

                Route::put('/users/{user}/school-admin', \App\Http\Controllers\ToggleSchoolAdminController::class)
                    ->name('users.school-admin');

                Route::get('/users/{user}/schools', [\App\Http\Controllers\UserSchoolController::class, 'index'])
                    ->name('users.schools');
                Route::put('/users/{user}/schools', [\App\Http\Controllers\UserSchoolController::class, 'update']);

                Route::post('/search/users', \App\Http\Controllers\UserSearchController::class)
                    ->name('users.search');

                /**
                 * Payment methods
                 */
                Route::get('/payment-methods/all', \App\Http\Controllers\FetchPaymentMethodsController::class);
                Route::resource('/payment-methods', \App\Http\Controllers\PaymentMethodController::class)
                    ->except('destroy');

                /**
                 * Record exports
                 */
                Route::post('/export/{type}', \App\Http\Controllers\ExportRecordsController::class);
                Route::get('/exports/{export}', \App\Http\Controllers\DownloadExportController::class)
                    ->name('exports.download');
            });

        /**
         * Settings-based routes
         */
        Route::prefix('/settings')->group(function () {
            Route::post('personal', [\App\Http\Controllers\Settings\PersonalSettingsController::class, 'update']);
            Route::get('personal', [\App\Http\Controllers\Settings\PersonalSettingsController::class, 'index'])
                ->name('settings.personal');

            Route::middleware('can:edit school settings')->group(function () {
                Route::post('school', [\App\Http\Controllers\Settings\SchoolSettingsController::class, 'update']);
                Route::get('school', [\App\Http\Controllers\Settings\SchoolSettingsController::class, 'index'])
                    ->name('settings.school');

                Route::post('/school/sync', \App\Http\Controllers\SyncSchoolDataController::class);
            });

            Route::middleware('manages_tenancy')->group(function () {
                Route::post('tenant', [\App\Http\Controllers\Settings\TenantSettingsController::class, 'update']);
                Route::get('tenant', [\App\Http\Controllers\Settings\TenantSettingsController::class, 'index'])
                    ->name('settings.tenant');

                Route::resource('sync-times', \App\Http\Controllers\SyncTimeController::class)
                    ->only('index', 'store', 'destroy');

                Route::post('sync', \App\Http\Controllers\SyncSisDataController::class)
                    ->name('sis.sync');

                Route::put('sync/emails', \App\Http\Controllers\Settings\SaveSyncEmailsController::class)
                    ->name('sis.sync.emails');

                Route::get('sync/progress', \App\Http\Controllers\GetSisSyncBatchController::class)
                    ->name('sis.sync.batch');

                Route::put('users/{user}/manager', \App\Http\Controllers\Settings\UpdateTenancyManagerStatusController::class)
                    ->name('users.tenancy_manager');

                Route::middleware('self_hosted')->group(function () {
                    Route::put('tenant/schools', \App\Http\Controllers\Settings\SaveActiveSchoolsController::class)
                        ->name('tenant.schools');

                    Route::post('test', \App\Http\Controllers\SendTestMailController::class)
                        ->name('smtp.test');
                });
            });
        });
    });
});
