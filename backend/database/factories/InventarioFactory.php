<?php

namespace Database\Factories;

use App\Models\Inventario;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inventario>
 */
class InventarioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sucursal_id' => Sucursal::factory(),
            'codigo' => strtoupper(fake()->unique()->bothify('COD-###')),
            'descripcion' => fake()->words(3, true),
            'cantidad' => fake()->numberBetween(1, 50),
            'precio' => fake()->randomFloat(2, 5, 500),
        ];
    }
}
