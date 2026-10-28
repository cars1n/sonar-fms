<?php

namespace App\Models;

use App\Traits\HasResource;
use BeyondCode\Comments\Comment as Model;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * @mixin IdeHelperComment
 */
class Comment extends Model
{
    use HasFactory;
    use HasResource;

    public function markdown(): Attribute
    {
        return Attribute::get(fn () => Str::markdown($this->comment));
    }

    public function diff(): Attribute
    {
        return Attribute::get(
            fn () => $this->created_at->diffForHumans(['options' => Carbon::JUST_NOW])
        );
    }
}
