<?php

namespace Database\Factories;

use App\Models\Medio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Medio>
 */
class MedioFactory extends Factory
{
    protected $model = Medio::class;

    public function definition(): array
    {
        return [
            'nombre' => fake()->randomElement(['Efectivo', 'Transferencia', 'Cheque', 'Tarjeta']) . ' ' . fake()->unique()->numberBetween(1, 999),
            'estado' => 'ON',
        ];
    }
}
