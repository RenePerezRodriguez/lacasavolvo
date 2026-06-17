<?php

namespace Database\Factories;

use App\Models\Cuenta;
use App\Models\Sucursal;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venta>
 */
class VentaFactory extends Factory
{
    protected $model = Venta::class;

    public function definition(): array
    {
        return [
            'sucursal_id' => Sucursal::factory(),
            'fecha'       => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'tipo'        => fake()->randomElement(['CONTADO', 'CREDITO']),
            'cuenta_id'   => Cuenta::factory()->cliente(),
            'monto'       => 0,
            'descuento'   => 0,
            'total'       => 0,
            'acuenta'     => 0,
            'saldo'       => 0,
            'n_dev'       => 0,
            'pagado'      => 'POR COBRAR',
            'estado'      => 'PROFORMA',
        ];
    }

    public function valido(): static
    {
        return $this->state(['estado' => 'VALIDO', 'pagado' => 'PAGADO']);
    }

    public function contado(): static
    {
        return $this->state(['tipo' => 'CONTADO', 'pagado' => 'PAGADO']);
    }
}
