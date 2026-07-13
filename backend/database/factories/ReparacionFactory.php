<?php

namespace Database\Factories;

use App\Models\Reparacion;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reparacion>
 */
class ReparacionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $valorTotal = fake()->randomFloat(2, 20, 500);

        return [
            'sucursal_id' => Sucursal::factory(),
            'user_id' => User::factory(),
            'cliente' => fake()->name(),
            'cedula' => fake()->numerify('##########'),
            'telefono' => fake()->numerify('##########'),
            'modelo' => fake()->words(2, true),
            'valor_total' => $valorTotal,
            'abono' => fake()->randomFloat(2, 0, (float) $valorTotal),
            'fecha' => now()->toDateString(),
            'foto' => null,
            'estado' => 'pendiente',
        ];
    }
}
