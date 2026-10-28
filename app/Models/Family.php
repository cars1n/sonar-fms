<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use App\Traits\HasResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin IdeHelperFamily
 */
class Family extends Model
{
    use BelongsToSchool;
    use HasFactory;
    use HasResource;

    protected $guarded = [];

    public function scopeSearch(Builder $builder, string $search)
    {
        $builder->where('name', 'ilike', $search);
    }

    public function scopeFilter(Builder $builder, array $filters)
    {
        $builder->when($filters['s'] ?? null, function (Builder $builder, string $search) {
            $builder->search($search)
                ->orWhereHas('students', function (Builder $builder) use ($search) {
                    $builder->search($search);
                });
        })
            ->when($filters['students'] ?? null, function (Builder $builder, array $students) {
                $builder->whereHas('students', function (Builder $builder) use ($students) {
                    $builder->whereIn('students.uuid', $students);
                });
            });
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class)
            ->orderBy('grade_level');
    }
}
