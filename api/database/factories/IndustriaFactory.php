<?php

namespace Database\Factories;

use App\Models\Industria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Industria>
 */
class IndustriaFactory extends Factory
{
    protected $model = Industria::class;

    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->word(),
            'estado' => 'ON',
        ];
    }
}
