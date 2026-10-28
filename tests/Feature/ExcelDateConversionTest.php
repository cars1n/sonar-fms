<?php

namespace Tests\Feature;

use App\Traits\ConvertsExcelValues;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Tests\TestCase;

class ExcelDateConversionTest extends TestCase
{
    public function test_excel_dates_keep_their_wall_clock_time_across_timezone_history(): void
    {
        $serial = Date::formattedPHPToExcel(2021, 10, 1, 8);

        foreach ([
            'UTC' => '2021-10-01 08:00:00',
            'America/New_York' => '2021-10-01 12:00:00',
            'Africa/Lagos' => '2021-10-01 07:00:00',
            'Asia/Shanghai' => '2021-10-01 00:00:00',
        ] as $timezone => $expected) {
            $converter = new class($timezone)
            {
                use ConvertsExcelValues {
                    convertDateTime as public;
                    convertDate as public;
                }

                public object $user;

                public function __construct(string $timezone)
                {
                    $this->user = (object) ['timezone' => $timezone];
                }
            };

            $this->assertSame($expected, $converter->convertDateTime($serial), $timezone);
            $this->assertSame('2021-10-01', $converter->convertDate($serial), $timezone);
        }
    }
}
