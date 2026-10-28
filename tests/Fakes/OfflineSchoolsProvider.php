<?php

namespace Tests\Fakes;

use App\SisProviders\PowerSchoolProvider;

class OfflineSchoolsProvider extends PowerSchoolProvider
{
    public function getSchoolsFromSis(): array
    {
        return array_map(fn (int $id) => [
            'sis_id' => $id,
            'name' => "Test school {$id}",
            'school_number' => $id,
            'low_grade' => 0,
            'high_grade' => 12,
        ], [101, 102, 103]);
    }
}
