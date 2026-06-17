<?php

namespace Database\Factories;

use App\Models\Producto;
use App\Models\Venta;
use App\Models\Ventadetalle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ventadetalle>
 */
class VentadetalleFactory extends Factory
{
    protected $model = Ventadetalle::class;

    public function definition(): array
    {
        $producto = Producto::factory()->make();

        return [
            'venta_id'    => Venta::factory(),
            'producto_id' => Producto::factory(),
            'codigo'      => $producto->codigo,
            'descripcion' => $producto->descripcion,
            'marca'       => '',
            'costo'       => fake()->randomFloat(2, 20, 500),
            'p_norm'      => fake()->randomFloat(2, 20, 500),
            'p_fact'      => fake()->randomFloat(2, 25, 600),
            'cantidad'    => fake()->numberBetween(1, 10),
            'estado'      => 'VALIDO',
        ];
    }
}
