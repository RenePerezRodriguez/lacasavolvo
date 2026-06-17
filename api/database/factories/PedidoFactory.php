<?php

namespace Database\Factories;

use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pedido>
 */
class PedidoFactory extends Factory
{
    protected $model = Pedido::class;

    public function definition(): array
    {
        return [
            'sucursal_id' => Sucursal::factory(),
            'fecha'       => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'observacion' => fake()->optional()->sentence(),
            'user_id'     => User::factory(),
            'estado'      => 'PROFORMA',
            'impresion'   => 0,
        ];
    }

    public function valido(): static
    {
        return $this->state(['estado' => 'VALIDO']);
    }
}
