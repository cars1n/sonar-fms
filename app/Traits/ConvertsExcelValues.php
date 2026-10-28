<?php

namespace App\Traits;

use App\Utilities\NumberUtility;
use Brick\Money\Money;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use PhpOffice\PhpSpreadsheet\Shared\Date;

trait ConvertsExcelValues
{
    /**
     * Converts Excel's number format to a
     * Carbon instance and returns the date string
     */
    protected function convertDate($value): ?string
    {
        if (is_numeric($value)) {
            return Carbon::instance(Date::excelToDateTimeObject((float) $value))
                ->toDateString();
        }

        try {
            return Carbon::parse($value, $this->user->timezone)
                ->setTimezone(config('app.timezone'))
                ->toDateTimeString();
        } catch (InvalidFormatException $exception) {
            return null;
        }
    }

    protected function convertCurrency($value): ?int
    {
        $sanitized = abs(NumberUtility::sanitizeNumber($value));

        try {
            return Money::of($sanitized, $this->school->currency->code)
                ->getMinorAmount()
                ->toInt();
        } catch (\Exception $exception) {
            return null;
        }
    }

    protected function convertPercentage($value): float
    {
        return NumberUtility::convertPercentageFromUser($value);
    }

    /**
     * Converts Excel's number format to a
     * Carbon instance and returns the date/time string
     */
    protected function convertDateTime($value): ?string
    {
        if (is_numeric($value)) {
            return Carbon::instance(Date::excelToDateTimeObject((float) $value, $this->user->timezone))
                ->roundUnit('minute', 15)
                ->setTimezone(config('app.timezone'))
                ->toDateTimeString();
        }

        try {
            return Carbon::parse($value, $this->user->timezone)
                ->setTimezone(config('app.timezone'))
                ->toDateTimeString();
        } catch (InvalidFormatException $exception) {
            return null;
        }
    }

    protected function convertInt($value): int
    {
        return (int) $value;
    }
}
