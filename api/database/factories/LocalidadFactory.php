<?php

namespace Database\Factories;

use App\Models\Localidad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Localidad>
 */
class LocalidadFactory extends Factory
{
    protected $model = Localidad::class;

    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->city(),
            'estado' => 'ON',
        ];
    }
}
