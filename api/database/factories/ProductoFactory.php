<?php

namespace Database\Factories;

use App\Models\Industria;
use App\Models\Marca;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Producto>
 */
class ProductoFactory extends Factory
{
    protected $model = Producto::class;

    public function definition(): array
    {
        return [
            'codigo'       => strtoupper(fake()->unique()->lexify('??-####')),
            'descripcion'  => fake()->words(4, true),
            'marca_id'     => Marca::factory(),
            'industria_id' => Industria::factory(),
            'unidad'       => fake()->randomElement(['PZA', 'CJA', 'LTS', 'KGS']),
            'p_comp'       => fake()->randomFloat(2, 10, 500),
            'p_norm'       => fake()->randomFloat(2, 20, 600),
            'p_fact'       => fake()->randomFloat(2, 25, 700),
            'stock1'       => 20,
            'stock2'       => 0,
            'stock3'       => 0,
            'stock4'       => 0,
            'stock5'       => 0,
            'estado'       => 'ON',
        ];
    }

    public function sinStock(): static
    {
        return $this->state(['stock1' => 0, 'stock2' => 0, 'stock3' => 0, 'stock4' => 0, 'stock5' => 0]);
    }
}
