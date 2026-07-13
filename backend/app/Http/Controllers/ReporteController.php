<?php

namespace App\Http\Controllers;

use App\Models\Inventario;
use App\Models\Reparacion;
use App\Models\Sucursal;
use App\Models\Venta;
use Illuminate\Http\Request;

class ReporteController extends Controller
{
    public function index(Request $request)
    {
        $esMes = $request->query('periodo') === 'mes';
        $inicioMes = now()->startOfMonth();
        $sucursalId = $request->query('sucursal_id');

        $ventasQuery = Venta::query();
        $reparacionesQuery = Reparacion::query();
        $inventarioQuery = Inventario::query();
        if ($esMes) {
            // "ventas.created_at" calificado a propósito: el cálculo de costo_estimado
            // más abajo hace un join con "inventario", y esa tabla también tiene
            // sucursal_id — sin calificar, el where quedaría ambiguo entre las dos.
            $ventasQuery->where('ventas.created_at', '>=', $inicioMes);
            $reparacionesQuery->where('created_at', '>=', $inicioMes);
        }
        if ($sucursalId) {
            $ventasQuery->where('ventas.sucursal_id', $sucursalId);
            $reparacionesQuery->where('sucursal_id', $sucursalId);
            $inventarioQuery->where('sucursal_id', $sucursalId);
        }

        $reparacionesPorEstado = (clone $reparacionesQuery)
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        // Costo de las ventas que vienen de un producto de Inventario (join directo
        // a la tabla, no vía relación Eloquent, a propósito: así se cuenta también
        // el costo de productos ya borrados —soft delete— del catálogo, en vez de
        // perder ese dato histórico por el global scope de Inventario). Las ventas
        // de "producto libre" no tienen costo registrado, así que quedan fuera de
        // este cálculo — es una utilidad estimada, no exacta.
        $totalVentas = (clone $ventasQuery)->sum('valor');
        $costoVentas = (clone $ventasQuery)
            ->whereNotNull('ventas.inventario_id')
            ->join('inventario', 'ventas.inventario_id', '=', 'inventario.id')
            ->selectRaw('COALESCE(SUM(inventario.costo * COALESCE(ventas.cantidad, 1)), 0) as total')
            ->value('total');

        $respuesta = [
            'periodo' => $esMes ? 'mes' : 'todo',
            'sucursal_id' => $sucursalId ? (int) $sucursalId : null,
            'ventas' => [
                'cantidad' => (clone $ventasQuery)->count(),
                'total' => $totalVentas,
                'costo_estimado' => (float) $costoVentas,
                'utilidad_estimada' => (float) $totalVentas - (float) $costoVentas,
            ],
            'reparaciones' => [
                'total' => (clone $reparacionesQuery)->count(),
                'pendiente' => (int) ($reparacionesPorEstado['pendiente'] ?? 0),
                'en_proceso' => (int) ($reparacionesPorEstado['en_proceso'] ?? 0),
                'listo' => (int) ($reparacionesPorEstado['listo'] ?? 0),
                'entregado' => (int) ($reparacionesPorEstado['entregado'] ?? 0),
                'saldo_pendiente' => (clone $reparacionesQuery)->where('estado', '!=', 'entregado')
                    ->get()
                    ->sum('saldo'),
            ],
            // El inventario es una foto del estado actual, no tiene sentido acotarlo por periodo (sí por sucursal).
            'inventario' => [
                'productos' => (clone $inventarioQuery)->count(),
                'valor_total' => (clone $inventarioQuery)->selectRaw('COALESCE(SUM(cantidad * precio), 0) as total')->value('total'),
                'costo_total' => (clone $inventarioQuery)->selectRaw('COALESCE(SUM(cantidad * costo), 0) as total')->value('total'),
                'stock_bajo' => (clone $inventarioQuery)->stockBajo()->count(),
            ],
        ];

        if (! $sucursalId) {
            $respuesta['por_sucursal'] = Sucursal::orderBy('nombre')->get()->map(function (Sucursal $sucursal) use ($esMes, $inicioMes) {
                $ventas = Venta::where('sucursal_id', $sucursal->id);
                $reparaciones = Reparacion::where('sucursal_id', $sucursal->id);
                if ($esMes) {
                    $ventas->where('created_at', '>=', $inicioMes);
                    $reparaciones->where('created_at', '>=', $inicioMes);
                }

                return [
                    'id' => $sucursal->id,
                    'nombre' => $sucursal->nombre,
                    'ventas_total' => $ventas->sum('valor'),
                    'reparaciones_pendientes' => (clone $reparaciones)->where('estado', '!=', 'entregado')->count(),
                    'productos' => Inventario::where('sucursal_id', $sucursal->id)->count(),
                ];
            });
        }

        return response()->json($respuesta);
    }
}
