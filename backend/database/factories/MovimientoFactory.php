<?php

namespace Database\Factories;

use App\Models\Inventario;
use App\Models\Movimiento;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Movimiento>
 */
class MovimientoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventario_id' => Inventario::factory(),
            'sucursal_id' => Sucursal::factory(),
            'user_id' => User::factory(),
            'tipo' => fake()->randomElement(['entrada', 'salida']),
            'cantidad' => fake()->numberBetween(1, 20),
            'motivo' => fake()->randomElement(['Compra a proveedor', 'Trasladado a otra sede', 'Producto dañado', 'Ajuste de conteo']),
            'fecha' => now()->toDateString(),
        ];
    }
}
