<?php

namespace App\Providers;

use App\Models\Department;
use App\Models\Fee;
use App\Models\FeeCategory;
use App\Models\Invoice;
use App\Models\InvoiceImport;
use App\Models\InvoiceLayout;
use App\Models\InvoicePayment;
use App\Models\InvoiceRefund;
use App\Models\PaymentImport;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\ReceiptLayout;
use App\Models\Scholarship;
use App\Models\School;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Spatie\Activitylog\ActivityLogger;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        JsonResource::withoutWrapping();
        Inertia::setRootView('layouts.app');
        URL::forceScheme($this->app->isLocal() ? (parse_url(config('app.url'), PHP_URL_SCHEME) ?: 'http') : 'https');

        $currentTenant = function (): Tenant {
            /** @var Tenant|null $current */
            $current = Tenant::current();

            return $current ?? new Tenant();
        };

        $currentSchool = function (): School {
            $current = School::current();

            return $current ?? new School();
        };

        if (! $this->app->runningInConsole()) {
            $this->app->bind(Tenant::class, $currentTenant);
            $this->app->bind(School::class, $currentSchool);
        }

        Request::macro('tenant', $currentTenant);
        Request::macro('school', $currentSchool);
        Request::macro('wantsInertia', fn () => request()->header('x-inertia'));
        Request::macro('id', fn () => auth()->id());

        Relation::morphMap([
            'user' => User::class,
            'student' => Student::class,
            'invoice' => Invoice::class,
            'refund' => InvoiceRefund::class,
            'payment' => InvoicePayment::class,
            'invoice_import' => InvoiceImport::class,
            'payment_import' => PaymentImport::class,
            'fee' => Fee::class,
            'fee_category' => FeeCategory::class,
            'scholarship' => Scholarship::class,
            'receipt' => Receipt::class,
            'payment_method' => PaymentMethod::class,
            'department' => Department::class,
            'invoice_layout' => InvoiceLayout::class,
            'receipt_layout' => ReceiptLayout::class,
        ]);

        ActivityLogger::macro('component', function (string $component) {
            /** @var ActivityLogger $this */
            return $this->withProperty('component', $component);
        });
    }
}
