<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use App\Traits\BelongsToTenant;
use App\Traits\HasAmountAttribute;
use App\Traits\HasResource;
use App\Traits\ScopeToCurrentSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Searchable\Searchable;
use Spatie\Searchable\SearchResult;

/**
 * @mixin IdeHelperFee
 */
class Fee extends Model implements Searchable
{
    use BelongsToSchool;
    use BelongsToTenant;
    use HasAmountAttribute;
    use HasFactory;
    use HasResource;
    use ScopeToCurrentSchool;

    protected $guarded = [];

    public function scopeFilter(Builder $builder, array $filters)
    {
        $builder->select('fees.*');

        $builder->when($filters['s'] ?? null, function (Builder $builder, $search) {
            $builder->where(function (Builder $builder) use ($search) {
                $builder->where('fees.name', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%");
            });
        });

        $orderBy = $filters['orderBy'] ?? 'name';
        $orderDir = $filters['orderDir'] ?? 'asc';

        $builder->when($orderBy === 'fee_categories.name', function (Builder $builder) {
            $builder->leftJoin('fee_categories', 'fees.fee_category_id', '=', 'fee_categories.id');
        })->when($orderBy === 'departments.name', function (Builder $builder) {
            $builder->leftJoin('departments', 'fees.department_id', '=', 'departments.id');
        });

        $builder->orderBy($orderBy, $orderDir);

        $builder->orderBy('fees.name', $orderDir);
    }

    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function getSearchResult(): SearchResult
    {
        return new SearchResult(
            $this,
            $this->name,
            route('fees.show', $this)
        );
    }
}
