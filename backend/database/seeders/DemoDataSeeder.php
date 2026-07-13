<?php

namespace Database\Seeders;

use App\Models\Inventario;
use App\Models\Reparacion;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    /**
     * Datos de ejemplo para explorar la app: renombra las 4 sucursales,
     * crea un empleado por sucursal, y carga inventario/ventas/reparaciones
     * realistas (con algo de stock bajo y estados variados) en cada una.
     * Seguro de correr más de una vez: sucursales/usuarios/inventario usan
     * updateOrCreate; ventas/reparaciones solo se cargan si están vacías.
     */
    public function run(): void
    {
        $nombres = ['Sucursal Centro', 'Sucursal Norte', 'Sucursal Sur', 'Sucursal Mall'];
        $sucursales = Sucursal::orderBy('id')->get();
        foreach ($sucursales as $i => $sucursal) {
            if (isset($nombres[$i])) {
                $sucursal->update(['nombre' => $nombres[$i]]);
            }
        }
        [$centro, $norte, $sur, $mall] = $sucursales->all();

        $empleados = [
            $centro->id => ['username' => 'empleado_centro', 'name' => 'Empleado Centro'],
            $norte->id => ['username' => 'empleado_norte', 'name' => 'Empleado Norte'],
            $sur->id => ['username' => 'empleado_sur', 'name' => 'Empleado Sur'],
            $mall->id => ['username' => 'empleado_mall', 'name' => 'Empleado Mall'],
        ];
        $usuarioPorSucursal = [];
        foreach ($empleados as $sucursalId => $datos) {
            $usuarioPorSucursal[$sucursalId] = User::updateOrCreate(
                ['username' => $datos['username']],
                [
                    'name' => $datos['name'],
                    'email' => $datos['username'].'@relojeriajimmy.local',
                    'password' => Hash::make('123'),
                    'tipo' => 'empleado',
                    'sucursal_id' => $sucursalId,
                ]
            );
        }

        $inventarioPorSucursal = [
            $centro->id => [
                ['codigo' => 'REL-C1', 'descripcion' => 'Reloj Casio Clásico', 'cantidad' => 15, 'precio' => 45],
                ['codigo' => 'REL-C2', 'descripcion' => 'Reloj Citizen Automático', 'cantidad' => 4, 'precio' => 180],
                ['codigo' => 'ACC-C1', 'descripcion' => 'Correa de Cuero Negra', 'cantidad' => 25, 'precio' => 12],
                ['codigo' => 'ACC-C2', 'descripcion' => 'Pila Botón SR626', 'cantidad' => 3, 'precio' => 2],
            ],
            $norte->id => [
                ['codigo' => 'REL-N1', 'descripcion' => 'Reloj Fossil Cronógrafo', 'cantidad' => 8, 'precio' => 95],
                ['codigo' => 'REL-N2', 'descripcion' => 'Reloj Casio G-Shock', 'cantidad' => 2, 'precio' => 60],
                ['codigo' => 'ACC-N1', 'descripcion' => 'Correa Metálica Acero', 'cantidad' => 10, 'precio' => 20],
                ['codigo' => 'ACC-N2', 'descripcion' => 'Vidrio de Reloj Genérico', 'cantidad' => 30, 'precio' => 5],
            ],
            $sur->id => [
                ['codigo' => 'REL-S1', 'descripcion' => 'Reloj Seiko 5', 'cantidad' => 6, 'precio' => 150],
                ['codigo' => 'REL-S2', 'descripcion' => 'Reloj Invicta', 'cantidad' => 1, 'precio' => 220],
                ['codigo' => 'ACC-S1', 'descripcion' => 'Correa de Silicona', 'cantidad' => 40, 'precio' => 8],
                ['codigo' => 'ACC-S2', 'descripcion' => 'Corona de Repuesto', 'cantidad' => 5, 'precio' => 10],
            ],
            $mall->id => [
                ['codigo' => 'REL-M1', 'descripcion' => 'Reloj Guess Dama', 'cantidad' => 9, 'precio' => 130],
                ['codigo' => 'REL-M2', 'descripcion' => 'Reloj Armani Exchange', 'cantidad' => 3, 'precio' => 210],
                ['codigo' => 'ACC-M1', 'descripcion' => 'Pulsera Extensible', 'cantidad' => 18, 'precio' => 15],
                ['codigo' => 'ACC-M2', 'descripcion' => 'Kit Herramientas Relojero', 'cantidad' => 2, 'precio' => 35],
            ],
        ];

        $itemsCreados = [];
        foreach ($inventarioPorSucursal as $sucursalId => $items) {
            foreach ($items as $item) {
                $itemsCreados[$item['codigo']] = Inventario::updateOrCreate(
                    ['sucursal_id' => $sucursalId, 'codigo' => $item['codigo']],
                    $item + ['sucursal_id' => $sucursalId]
                );
            }
        }

        if (Venta::count() === 0) {
            $mesPasado = now()->subMonthNoOverflow();

            $ventas = [
                ['sucursal_id' => $centro->id, 'producto' => 'Reloj Casio Clásico', 'cantidad' => 2, 'valor' => 90, 'inventario_id' => $itemsCreados['REL-C1']->id, 'created_at' => now()->subDays(3)],
                ['sucursal_id' => $centro->id, 'producto' => 'Cambio de pila', 'cantidad' => 1, 'valor' => 5, 'inventario_id' => null, 'created_at' => $mesPasado],
                ['sucursal_id' => $norte->id, 'producto' => 'Reloj Casio G-Shock', 'cantidad' => 1, 'valor' => 60, 'inventario_id' => $itemsCreados['REL-N2']->id, 'created_at' => now()->subDay()],
                ['sucursal_id' => $sur->id, 'producto' => 'Correa de Silicona', 'cantidad' => 3, 'valor' => 24, 'inventario_id' => $itemsCreados['ACC-S1']->id, 'created_at' => now()->subDays(2)],
                ['sucursal_id' => $sur->id, 'producto' => 'Ajuste de correa (servicio)', 'cantidad' => 1, 'valor' => 8, 'inventario_id' => null, 'created_at' => $mesPasado],
                ['sucursal_id' => $mall->id, 'producto' => 'Reloj Guess Dama', 'cantidad' => 1, 'valor' => 130, 'inventario_id' => $itemsCreados['REL-M1']->id, 'created_at' => now()->subHours(5)],
            ];

            foreach ($ventas as $venta) {
                $fecha = $venta['created_at'];
                Venta::create([
                    'sucursal_id' => $venta['sucursal_id'],
                    'producto' => $venta['producto'],
                    'cantidad' => $venta['cantidad'],
                    'valor' => $venta['valor'],
                    'inventario_id' => $venta['inventario_id'],
                    'user_id' => $usuarioPorSucursal[$venta['sucursal_id']]->id,
                    'fecha' => $fecha->toDateString(),
                    'created_at' => $fecha,
                    'updated_at' => $fecha,
                ]);
            }
        }

        if (Reparacion::count() === 0) {
            $mesPasado = now()->subMonthNoOverflow();

            $reparaciones = [
                ['sucursal_id' => $centro->id, 'cliente' => 'Juan Pérez', 'modelo' => 'Casio Edifice', 'valor_total' => 80, 'abono' => 30, 'estado' => 'en_proceso', 'created_at' => now()->subDays(4)],
                ['sucursal_id' => $centro->id, 'cliente' => 'María Gómez', 'modelo' => 'Rolex Submariner (réplica)', 'valor_total' => 500, 'abono' => 500, 'estado' => 'entregado', 'created_at' => $mesPasado],
                ['sucursal_id' => $norte->id, 'cliente' => 'Pedro Ramírez', 'modelo' => 'Fossil Cronógrafo', 'valor_total' => 60, 'abono' => 0, 'estado' => 'pendiente', 'created_at' => now()->subDay()],
                ['sucursal_id' => $norte->id, 'cliente' => 'Ana Torres', 'modelo' => 'Seiko 5', 'valor_total' => 120, 'abono' => 60, 'estado' => 'listo', 'created_at' => now()->subDays(6)],
                ['sucursal_id' => $sur->id, 'cliente' => 'Carlos Díaz', 'modelo' => 'Invicta', 'valor_total' => 200, 'abono' => 100, 'estado' => 'en_proceso', 'created_at' => now()->subDays(2)],
                ['sucursal_id' => $mall->id, 'cliente' => 'Lucía Fernández', 'modelo' => 'Guess Dama', 'valor_total' => 90, 'abono' => 90, 'estado' => 'entregado', 'created_at' => $mesPasado],
                ['sucursal_id' => $mall->id, 'cliente' => 'Diego Martínez', 'modelo' => 'Armani Exchange', 'valor_total' => 150, 'abono' => 0, 'estado' => 'pendiente', 'created_at' => now()->subHours(10)],
            ];

            foreach ($reparaciones as $reparacion) {
                $fecha = $reparacion['created_at'];
                Reparacion::create([
                    'sucursal_id' => $reparacion['sucursal_id'],
                    'cliente' => $reparacion['cliente'],
                    'modelo' => $reparacion['modelo'],
                    'valor_total' => $reparacion['valor_total'],
                    'abono' => $reparacion['abono'],
                    'estado' => $reparacion['estado'],
                    'user_id' => $usuarioPorSucursal[$reparacion['sucursal_id']]->id,
                    'fecha' => $fecha->toDateString(),
                    'created_at' => $fecha,
                    'updated_at' => $fecha,
                ]);
            }
        }
    }
}
