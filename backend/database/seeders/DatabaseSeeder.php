<?php

namespace Database\Seeders;

use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $primeraSucursal = Sucursal::orderBy('id')->first();

        User::factory()->create([
            'name' => 'Propietario',
            'username' => 'propietario',
            'email' => 'propietario@relojeriajimmy.local',
            'password' => Hash::make('123'),
            'tipo' => 'admin',
            'sucursal_id' => null,
        ]);

        User::factory()->create([
            'name' => 'Empleada',
            'username' => 'empleada',
            'email' => 'empleada@relojeriajimmy.local',
            'password' => Hash::make('123'),
            'tipo' => 'empleado',
            'sucursal_id' => $primeraSucursal?->id,
        ]);
    }
}
