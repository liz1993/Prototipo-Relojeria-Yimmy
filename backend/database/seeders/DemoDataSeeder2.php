<?php

namespace Database\Seeders;

use App\Models\Inventario;
use App\Models\Reparacion;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class DemoDataSeeder2 extends Seeder
{
    /**
     * Segunda tanda de datos de ejemplo: más productos, ventas y reparaciones
     * (con un par de fotos reales) encima de lo que ya cargó DemoDataSeeder.
     * Usa updateOrCreate para inventario (seguro de re-correr); ventas y
     * reparaciones se agregan siempre como registros nuevos.
     */
    public function run(): void
    {
        $sucursales = Sucursal::orderBy('id')->get();
        [$centro, $norte, $sur, $mall] = $sucursales->all();

        $usuarioPorSucursal = [
            $centro->id => User::where('username', 'empleado_centro')->first(),
            $norte->id => User::where('username', 'empleado_norte')->first(),
            $sur->id => User::where('username', 'empleado_sur')->first(),
            $mall->id => User::where('username', 'empleado_mall')->first(),
        ];

        $inventarioPorSucursal = [
            $centro->id => [
                ['codigo' => 'REL-C3', 'descripcion' => 'Reloj Timex Weekender', 'cantidad' => 10, 'precio' => 55],
                ['codigo' => 'ACC-C3', 'descripcion' => 'Correa NATO Verde', 'cantidad' => 20, 'precio' => 10],
                ['codigo' => 'ACC-C4', 'descripcion' => 'Cristal Mineral 30mm', 'cantidad' => 4, 'precio' => 8],
            ],
            $norte->id => [
                ['codigo' => 'REL-N3', 'descripcion' => 'Reloj Swatch Colorido', 'cantidad' => 14, 'precio' => 70],
                ['codigo' => 'ACC-N3', 'descripcion' => 'Correa de Tela', 'cantidad' => 22, 'precio' => 9],
                ['codigo' => 'ACC-N4', 'descripcion' => 'Pila CR2032', 'cantidad' => 5, 'precio' => 3],
            ],
            $sur->id => [
                ['codigo' => 'REL-S3', 'descripcion' => 'Reloj Orient Automático', 'cantidad' => 5, 'precio' => 175],
                ['codigo' => 'ACC-S3', 'descripcion' => 'Hebilla de Acero', 'cantidad' => 15, 'precio' => 6],
                ['codigo' => 'ACC-S4', 'descripcion' => 'Kit Limpieza Relojes', 'cantidad' => 12, 'precio' => 14],
            ],
            $mall->id => [
                ['codigo' => 'REL-M3', 'descripcion' => 'Reloj Michael Kors', 'cantidad' => 6, 'precio' => 190],
                ['codigo' => 'ACC-M3', 'descripcion' => 'Correa Milanesa', 'cantidad' => 9, 'precio' => 18],
                ['codigo' => 'ACC-M4', 'descripcion' => 'Estuche para Reloj', 'cantidad' => 3, 'precio' => 12],
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

        $ventas = [
            ['sucursal_id' => $centro->id, 'producto' => 'Reloj Timex Weekender', 'cantidad' => 1, 'valor' => 55, 'inventario_id' => $itemsCreados['REL-C3']->id, 'dias' => 0],
            ['sucursal_id' => $centro->id, 'producto' => 'Correa NATO Verde', 'cantidad' => 2, 'valor' => 20, 'inventario_id' => $itemsCreados['ACC-C3']->id, 'dias' => 9],
            ['sucursal_id' => $centro->id, 'producto' => 'Ajuste de brazalete (servicio)', 'cantidad' => 1, 'valor' => 6, 'inventario_id' => null, 'dias' => 45],
            ['sucursal_id' => $norte->id, 'producto' => 'Reloj Swatch Colorido', 'cantidad' => 1, 'valor' => 70, 'inventario_id' => $itemsCreados['REL-N3']->id, 'dias' => 1],
            ['sucursal_id' => $norte->id, 'producto' => 'Pila CR2032', 'cantidad' => 3, 'valor' => 9, 'inventario_id' => $itemsCreados['ACC-N4']->id, 'dias' => 12],
            ['sucursal_id' => $sur->id, 'producto' => 'Reloj Orient Automático', 'cantidad' => 1, 'valor' => 175, 'inventario_id' => $itemsCreados['REL-S3']->id, 'dias' => 3],
            ['sucursal_id' => $sur->id, 'producto' => 'Kit Limpieza Relojes', 'cantidad' => 2, 'valor' => 28, 'inventario_id' => $itemsCreados['ACC-S4']->id, 'dias' => 20],
            ['sucursal_id' => $mall->id, 'producto' => 'Reloj Michael Kors', 'cantidad' => 1, 'valor' => 190, 'inventario_id' => $itemsCreados['REL-M3']->id, 'dias' => 2],
            ['sucursal_id' => $mall->id, 'producto' => 'Estuche para Reloj', 'cantidad' => 1, 'valor' => 12, 'inventario_id' => $itemsCreados['ACC-M4']->id, 'dias' => 60],
        ];

        foreach ($ventas as $venta) {
            $fecha = now()->subDays($venta['dias']);
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

        $fotoDemo = null;
        $origenFoto = dirname(base_path()).DIRECTORY_SEPARATOR.'diagrama.png';
        if (File::exists($origenFoto)) {
            $fotoDemo = File::get($origenFoto);
        }

        $reparaciones = [
            ['sucursal_id' => $centro->id, 'cliente' => 'Sofía Ramírez', 'modelo' => 'Timex Weekender', 'valor_total' => 40, 'abono' => 20, 'estado' => 'listo', 'dias' => 5, 'foto' => true],
            ['sucursal_id' => $centro->id, 'cliente' => 'Andrés Molina', 'modelo' => 'Casio Vintage', 'valor_total' => 35, 'abono' => 0, 'estado' => 'pendiente', 'dias' => 0],
            ['sucursal_id' => $norte->id, 'cliente' => 'Valentina Ruiz', 'modelo' => 'Swatch', 'valor_total' => 45, 'abono' => 45, 'estado' => 'entregado', 'dias' => 15],
            ['sucursal_id' => $norte->id, 'cliente' => 'Pedro Ramírez', 'modelo' => 'Fossil Cronógrafo', 'valor_total' => 60, 'abono' => 60, 'estado' => 'entregado', 'dias' => 4, 'foto' => true],
            ['sucursal_id' => $sur->id, 'cliente' => 'Camila Ortiz', 'modelo' => 'Orient Automático', 'valor_total' => 220, 'abono' => 50, 'estado' => 'en_proceso', 'dias' => 1],
            ['sucursal_id' => $sur->id, 'cliente' => 'Felipe Castro', 'modelo' => 'Invicta', 'valor_total' => 90, 'abono' => 0, 'estado' => 'pendiente', 'dias' => 8],
            ['sucursal_id' => $mall->id, 'cliente' => 'Isabella Vargas', 'modelo' => 'Michael Kors', 'valor_total' => 130, 'abono' => 65, 'estado' => 'listo', 'dias' => 6, 'foto' => true],
            ['sucursal_id' => $mall->id, 'cliente' => 'Diego Martínez', 'modelo' => 'Armani Exchange', 'valor_total' => 150, 'abono' => 50, 'estado' => 'en_proceso', 'dias' => 2],
        ];

        foreach ($reparaciones as $reparacion) {
            $fecha = now()->subDays($reparacion['dias']);
            $rutaFoto = null;
            if (! empty($reparacion['foto']) && $fotoDemo) {
                $rutaFoto = 'reparaciones/demo-'.uniqid().'.png';
                Storage::disk('public')->put($rutaFoto, $fotoDemo);
            }

            Reparacion::create([
                'sucursal_id' => $reparacion['sucursal_id'],
                'cliente' => $reparacion['cliente'],
                'modelo' => $reparacion['modelo'],
                'valor_total' => $reparacion['valor_total'],
                'abono' => $reparacion['abono'],
                'estado' => $reparacion['estado'],
                'foto' => $rutaFoto,
                'user_id' => $usuarioPorSucursal[$reparacion['sucursal_id']]->id,
                'fecha' => $fecha->toDateString(),
                'created_at' => $fecha,
                'updated_at' => $fecha,
            ]);
        }
    }
}
