<?php

namespace Database\Factories;

use App\Models\Cuenta;
use App\Models\Empresa;
use App\Models\Localidad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cuenta>
 */
class CuentaFactory extends Factory
{
    protected $model = Cuenta::class;

    public function definition(): array
    {
        return [
            'nombre'       => fake()->company(),
            'nit'          => fake()->numerify('##########'),
            'empresa_id'   => Empresa::factory(),
            'localidad_id' => Localidad::factory(),
            'departamento' => 'COCHABAMBA',
            'tipo'         => fake()->randomElement(['CLIENTE', 'PROVEEDOR', 'AMBOS']),
            'telefono'     => fake()->phoneNumber(),
            'direccion'    => fake()->streetAddress(),
            'saldo'        => 0,
            'estado'       => 'ON',
        ];
    }

    public function cliente(): static
    {
        return $this->state(['tipo' => 'CLIENTE']);
    }

    public function proveedor(): static
    {
        return $this->state(['tipo' => 'PROVEEDOR']);
    }
}
