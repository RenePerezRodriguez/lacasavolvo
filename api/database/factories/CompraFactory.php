<?php

namespace Database\Factories;

use App\Models\Compra;
use App\Models\Cuenta;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Compra>
 */
class CompraFactory extends Factory
{
    protected $model = Compra::class;

    public function definition(): array
    {
        return [
            'sucursal_id' => Sucursal::factory(),
            'fecha'       => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'tipo'        => fake()->randomElement(['CONTADO', 'CREDITO']),
            'cuenta_id'   => Cuenta::factory()->proveedor(),
            'user_id'     => User::factory(),
            'monto'       => 0,
            'descuento'   => 0,
            'total'       => 0,
            'acuenta'     => 0,
            'saldo'       => 0,
            'n_dev'       => 0,
            'pagado'      => 'POR PAGAR',
            'estado'      => 'PROFORMA',
        ];
    }

    public function valido(): static
    {
        return $this->state(['estado' => 'VALIDO', 'pagado' => 'PAGADO']);
    }
}
