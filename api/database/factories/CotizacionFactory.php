<?php

namespace Database\Factories;

use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cotizacion>
 */
class CotizacionFactory extends Factory
{
    protected $model = Cotizacion::class;

    public function definition(): array
    {
        return [
            'sucursal_id' => Sucursal::factory(),
            'fecha'       => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'cuenta_id'   => Cuenta::factory()->cliente(),
            'user_id'     => User::factory(),
            'monto'       => 0,
            'descuento'   => 0,
            'total'       => 0,
            'observacion' => '',
            'estado'      => 'VALIDO',
        ];
    }
}
