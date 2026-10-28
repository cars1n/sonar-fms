<?php

namespace App\Models;

use App\Http\Requests\SaveLayoutRequest;
use App\Traits\BelongsToSchool;
use App\Traits\BelongsToTenant;
use App\Traits\HasResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperLayoutBase
 */
class LayoutBase extends Model
{
    use BelongsToSchool;
    use BelongsToTenant;
    use HasFactory;
    use HasResource;

    protected $guarded = [];

    protected $casts = [
        'layout_data' => 'json',
        'is_default' => 'boolean',
    ];

    public function scopeFilter(Builder $builder, array $filters)
    {
        $builder->when($filters['s'] ?? null, function (Builder $builder, string $search) {
            $builder->where('name', 'ilike', "%{$search}%");
        });

        $orderBy = $filters['orderBy'] ?? 'name';
        $orderDir = $filters['orderDir'] ?? 'asc';

        $builder->orderBy($orderBy, $orderDir);
        $builder->orderBy('name', $orderDir);
    }

    public function scopeDefault(Builder $builder, bool $status = true)
    {
        $builder->where('is_default', $status);
    }

    public function maxWidth(): Attribute
    {
        $pages = [
            'Letter' => '8.5in',
            'A4' => '8.27in',
        ];

        return Attribute::get(
            fn (): string => $pages[$this->paper_size]
        );
    }

    public static function saveFromRequest(SaveLayoutRequest $request): static
    {
        $data = $request->validated();
        $school = $request->school();

        $data['school_id'] = $school->id;
        $data['tenant_id'] = $school->tenant_id;
        // If a default layout doesn't exist, set it to be this one
        $data['is_default'] = static::default()
            ->where('school_id', $school->id)
            ->doesntExist();

        return static::create($data);
    }

    public static function makeDefault(): static
    {
        return new static([
            'paper_size' => 'A4',
            'is_default' => true,
            'layout_data' => [
                'rows' => [
                    [
                        'isContentTable' => true,
                        'columns' => [],
                    ],
                ],
            ],
        ]);
    }
}
