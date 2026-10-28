<?php

namespace Tests\Feature;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\TestCase;

class ResourceResolutionTest extends TestCase
{
    public function test_model_resource_detection_remains_compatible_with_laravel(): void
    {
        $user = $this->createUser();
        $resource = $user->toResource();

        $this->assertInstanceOf(UserResource::class, $resource);
        $this->assertSame($user, $resource->resource);

        $explicit = $user->toResource(JsonResource::class);
        $this->assertSame(JsonResource::class, $explicit::class);
        $this->assertSame($user, $explicit->resource);
    }

    public function test_static_resource_detection_still_handles_collections(): void
    {
        $user = $this->createUser();
        $resource = User::resource(collect([$user]));

        $this->assertInstanceOf(AnonymousResourceCollection::class, $resource);
        $this->assertSame(UserResource::class, $resource->collects);
        $this->assertSame($user, $resource->collection->first()->resource);
    }
}
