<?php

namespace Database\Factories;

use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venta>
 */
class VentaFactory extends Factory
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
            'user_id' => User::factory(),
            'producto' => fake()->words(2, true),
            'cantidad' => fake()->numberBetween(1, 5),
            'valor' => fake()->randomFloat(2, 5, 300),
            'fecha' => now()->toDateString(),
        ];
    }
}
