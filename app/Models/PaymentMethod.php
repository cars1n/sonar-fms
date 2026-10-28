<?php

namespace App\Models;

use App\Exceptions\PaymentMethodDriverNotFound;
use App\PaymentMethods\BankTransfer;
use App\PaymentMethods\Cash;
use App\PaymentMethods\PaymentMethodDriver;
use App\PaymentMethods\Skiply;
use App\Traits\BelongsToSchool;
use App\Traits\BelongsToTenant;
use App\Traits\HasResource;
use App\Traits\ScopeToCurrentSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperPaymentMethod
 */
class PaymentMethod extends Model
{
    use BelongsToSchool;
    use BelongsToTenant;
    use HasFactory;
    use HasResource;
    use ScopeToCurrentSchool;

    protected $guarded = [];

    public bool $includeDriverWithResource = true;

    protected PaymentMethodDriver $paymentMethodDriver;

    protected $casts = [
        'options' => 'json',
        'active' => 'boolean',
        'show_on_invoice' => 'boolean',
    ];

    public function scopeShowOnInvoice(Builder $builder)
    {
        $builder->where('show_on_invoice', true)
            ->where('active', true);
    }

    public function getNameAttribute(): string
    {
        return $this->getDriver()
            ->label();
    }

    public static function drivers(): array
    {
        return [
            'bank_transfer' => BankTransfer::class,
            'cash' => Cash::class,
            'skiply' => Skiply::class,
        ];
    }

    public static function getAllDrivers(): array
    {
        return array_map(
            fn ($class) => new $class,
            static::drivers()
        );
    }

    public static function options(): array
    {
        return collect(static::drivers())
            ->mapWithKeys(function ($driver) {
                /** @var PaymentMethodDriver $instance */
                $instance = new $driver;

                return [$instance->key() => $instance->label()];
            })
            ->toArray();
    }

    public static function makeDriver(string $driverName): PaymentMethodDriver
    {
        $drivers = static::drivers();

        $driver = $drivers[$driverName] ?? null;

        if (! $driver || ! class_exists($driver)) {
            throw new PaymentMethodDriverNotFound("The {$driverName} payment method driver could not be found.");
        }

        return new $driver;
    }

    public function getDriver(): PaymentMethodDriver
    {
        if (isset($this->paymentMethodDriver)) {
            return $this->paymentMethodDriver;
        }

        $this->paymentMethodDriver = static::makeDriver($this->driver)
            ->setPaymentMethod($this);

        return $this->paymentMethodDriver;
    }

    public static function getListForSchool(School $school): array
    {
        $paymentMethods = $school
            ->paymentMethods()
            ->orderBy('driver')
            ->get()
            ->keyBy('driver');

        return array_reduce(
            PaymentMethod::getAllDrivers(),
            function (array $methods, PaymentMethodDriver $driver) use ($paymentMethods) {
                /** @var static $paymentMethod */
                $paymentMethod = $paymentMethods->get($driver->key(), new PaymentMethod);

                if (! $paymentMethod->id) {
                    return $methods;
                }

                $paymentMethod->includeDriverWithResource = false;

                $driver->setPaymentMethod($paymentMethod)
                    ->setIncludePaymentMethodInResource(true);

                $methods[] = $driver;

                return $methods;
            },
            []
        );
    }
}
