<?php

namespace Database\Factories;

use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sucursal>
 */
class SucursalFactory extends Factory
{
    protected $model = Sucursal::class;

    public function definition(): array
    {
        return [
            'nombre'    => fake()->city() . ' ' . fake()->randomElement(['Central', 'Norte', 'Sur', 'Este']),
            'alias'     => strtoupper(fake()->lexify('??')),
            'nit'       => fake()->numerify('##########'),
            'direccion' => fake()->streetAddress(),
            'telefono'  => fake()->phoneNumber(),
            'email'     => fake()->companyEmail(),
            'estado'    => 'ON',
        ];
    }
}
