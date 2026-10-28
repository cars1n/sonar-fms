<?php

namespace App\Models;

use App\ResolutionStrategies\Greatest;
use App\ResolutionStrategies\Least;
use App\Traits\BelongsToSchool;
use App\Traits\BelongsToTenant;
use App\Traits\HasAmountAttribute;
use App\Traits\HasPercentageAttribute;
use App\Traits\HasResource;
use App\Traits\ScopeToCurrentSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Searchable\Searchable;
use Spatie\Searchable\SearchResult;

/**
 * @mixin IdeHelperScholarship
 */
class Scholarship extends Model implements Searchable
{
    use BelongsToSchool;
    use BelongsToTenant;
    use HasAmountAttribute;
    use HasFactory;
    use HasPercentageAttribute;
    use HasResource;
    use ScopeToCurrentSchool;

    protected $guarded = [];

    protected $casts = [
        'percentage' => 'float',
    ];

    public function scopeFilter(Builder $builder, array $filters)
    {
        $builder->when($filters['s'] ?? null, function (Builder $builder, $search) {
            $builder->where(function (Builder $builder) use ($search) {
                $builder->where('name', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%");
            });
        });

        $orderBy = $filters['orderBy'] ?? 'name';
        $orderDir = $filters['orderDir'] ?? 'asc';

        $builder->orderBy($orderBy, $orderDir);
        $builder->orderBy('scholarships.name', $orderDir);
    }

    public static function getResolutionStrategies(): array
    {
        return [
            Least::class => __('Least'),
            Greatest::class => __('Greatest'),
        ];
    }

    public function getSearchResult(): SearchResult
    {
        return new SearchResult(
            $this,
            $this->name,
            route('scholarships.show', $this)
        );
    }
}
