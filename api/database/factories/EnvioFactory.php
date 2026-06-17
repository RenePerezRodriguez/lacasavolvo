<?php

namespace Database\Factories;

use App\Models\Envio;
use App\Models\Medio;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Envio>
 */
class EnvioFactory extends Factory
{
    protected $model = Envio::class;

    public function definition(): array
    {
        return [
            'sucursal_id' => Sucursal::factory(),
            'fecha'       => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'cuenta_id'   => Sucursal::factory(), // destination sucursal
            'medio_id'    => Medio::factory(),
            'monto'       => 0,
            'pagado'      => 'PAGADO',
            'n_dev'       => 0,
            'observacion' => null,
            'estado'      => 'PROFORMA',
        ];
    }

    public function enviado(): static
    {
        return $this->state(['estado' => 'ENVIADO']);
    }
}
