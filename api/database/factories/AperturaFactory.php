<?php

namespace Database\Factories;

use App\Models\Apertura;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Apertura>
 */
class AperturaFactory extends Factory
{
    protected $model = Apertura::class;

    public function definition(): array
    {
        return [
            'sucursal_id' => Sucursal::factory(),
            'fecha'       => now()->toDateString(),
            'apertura'    => fake()->randomFloat(2, 0, 500),
            'user_id'     => User::factory(),
            'cerrado'     => 'NO',
            'estado'      => 'ON',
        ];
    }

    public function cerrada(): static
    {
        return $this->state(['cerrado' => 'SI']);
    }
}
