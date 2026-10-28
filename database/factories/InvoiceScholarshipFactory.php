<?php

namespace Database\Factories;

use App\Models\InvoiceScholarship;
use App\ResolutionStrategies\Least;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class InvoiceScholarshipFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = InvoiceScholarship::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'name' => $this->faker->word,
            'amount' => $this->faker->numberBetween(1, 10000),
            'resolution_strategy' => Least::class,
        ];
    }

    public function percentage()
    {
        return $this->state([
            'amount' => null,
            'percentage' => $this->faker->randomFloat(5, 0.001, 1),
        ]);
    }
}
