<?php

namespace App\Models;

use App\Traits\HasResource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperTerm
 */
class Term extends Model
{
    use HasFactory;
    use HasResource;

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
    ];

    public function getIsCurrentAttribute(): bool
    {
        $today = today();

        return $this->starts_at <= $today &&
            $this->ends_at >= $today;
    }

    public function getSchoolYearsAttribute(): string
    {
        return $this->buildSchoolYears($this->start_year);
    }

    public function getNextSchoolYearsAttribute(): string
    {
        return $this->buildSchoolYears($this->start_year + 1);
    }

    public function buildSchoolYears(string|int $year): string
    {
        $start = substr((string) $year, 2);
        $end = substr((string) ($year + 1), 2);

        return $start.'-'.$end;
    }

    public static function makeFromNow(): static
    {
        $today = CarbonImmutable::parse(today());
        $ends = $today->addYear();

        return new Term([
            'starts_at' => $today->toDateString(),
            'ends_at' => $today->addYear()->toDateString(),
            'start_year' => $today->year,
            'portion' => 1,
            'name' => $today->year.'-'.$ends->year,
            'abbreviation' => $today->format('y').'-'.$ends->format('y'),
        ]);
    }
}
