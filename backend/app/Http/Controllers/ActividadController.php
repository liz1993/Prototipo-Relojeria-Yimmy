<?php

namespace App\Http\Controllers;

use App\Models\Movimiento;
use App\Models\RegistroActividad;
use App\Models\Reparacion;
use Illuminate\Http\Request;

/**
 * Feed de actividad para el dashboard del admin: junta ventas (desde la
 * bitácora de auditoría -- ver RegistroActividad/VentaObserver, la única
 * forma de que una edición o un borrado queden visibles), reparaciones y
 * movimientos de inventario de todas las sucursales en una sola lista
 * ordenada por fecha, para poder ver de un vistazo qué pasó en cada sede.
 * Se arma en PHP (no con un UNION de SQL) a propósito: las fuentes tienen
 * formas distintas, y fusionar consultas ya traídas es más simple de
 * mantener que un UNION con columnas forzadas a coincidir.
 */
class ActividadController extends Controller
{
    /** Máximo de eventos a devolver si no se pide un límite explícito. */
    private const LIMITE_POR_DEFECTO = 100;

    public function index(Request $request)
    {
        $sucursalId = $request->query('sucursal_id');
        $desde = $request->query('desde');
        $hasta = $request->query('hasta');
        $modulo = $request->query('modulo', 'relojeria');
        $limite = min((int) $request->query('limite', self::LIMITE_POR_DEFECTO), 500);

        // Filtro común a Reparacion y Movimiento (sobre su propia "fecha" de
        // negocio). Los registros de Venta se arman aparte más abajo, desde
        // RegistroActividad, filtrando por "created_at" (cuándo pasó la acción)
        // en vez de la fecha de la venta -- una venta ya borrada no tiene
        // "fecha" fiable para filtrar, pero el evento de auditoría sí sabe
        // cuándo ocurrió. "modulo" tampoco se aplica acá para Movimiento: no
        // tiene esa columna (se filtra vía el producto de inventario, abajo).
        $aplicarFiltros = function ($query) use ($sucursalId, $desde, $hasta) {
            if ($sucursalId) {
                $query->where('sucursal_id', $sucursalId);
            }
            if ($desde) {
                $query->whereDate('fecha', '>=', $desde);
            }
            if ($hasta) {
                $query->whereDate('fecha', '<=', $hasta);
            }

            return $query;
        };

        $ventas = RegistroActividad::with(['user:id,name,username', 'sucursal:id,nombre'])
            ->where('entidad', 'venta')
            ->where('modulo', $modulo)
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->when($desde, fn ($q) => $q->whereDate('created_at', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('created_at', '<=', $hasta))
            ->orderByDesc('created_at')
            ->limit($limite)
            ->get()
            ->map(fn (RegistroActividad $r) => [
                'tipo' => 'venta_'.$r->accion, // venta_creado / venta_modificado / venta_eliminado
                'sucursal' => $r->sucursal ? ['id' => $r->sucursal->id, 'nombre' => $r->sucursal->nombre] : null,
                'usuario' => $r->user->username ?? null,
                'descripcion' => $r->descripcion,
                'monto' => $r->monto !== null ? (float) $r->monto : null,
                'fecha' => $r->created_at->toDateString(),
                'created_at' => $r->created_at,
            ]);

        $reparaciones = $aplicarFiltros(Reparacion::with(['user:id,name,username', 'sucursal:id,nombre'])->where('modulo', $modulo))
            ->orderByDesc('created_at')
            ->limit($limite)
            ->get()
            ->map(fn (Reparacion $r) => [
                'tipo' => 'reparacion',
                'sucursal' => $r->sucursal ? ['id' => $r->sucursal->id, 'nombre' => $r->sucursal->nombre] : null,
                'usuario' => $r->user->username ?? null,
                'descripcion' => "Reparación: {$r->cliente}".($r->modelo ? " - {$r->modelo}" : '')." ({$r->estado})",
                'monto' => $r->valor_total !== null ? (float) $r->valor_total : null,
                'fecha' => optional($r->fecha)->toDateString(),
                'created_at' => $r->created_at,
            ]);

        $movimientos = $aplicarFiltros(Movimiento::with(['user:id,name,username', 'sucursal:id,nombre', 'inventario:id,descripcion'])
            ->whereHas('inventario', fn ($q) => $q->where('modulo', $modulo)))
            ->orderByDesc('created_at')
            ->limit($limite)
            ->get()
            ->map(fn (Movimiento $m) => [
                'tipo' => 'movimiento_'.$m->tipo, // "movimiento_entrada" / "movimiento_salida"
                'sucursal' => $m->sucursal ? ['id' => $m->sucursal->id, 'nombre' => $m->sucursal->nombre] : null,
                'usuario' => $m->user->username ?? null,
                'descripcion' => ($m->tipo === 'entrada' ? 'Entrada: ' : 'Salida: ')
                    ."{$m->cantidad} x ".($m->inventario->descripcion ?? '(producto eliminado)')." ({$m->motivo})",
                'monto' => null,
                'fecha' => optional($m->fecha)->toDateString(),
                'created_at' => $m->created_at,
            ]);

        $feed = $ventas->concat($reparaciones)->concat($movimientos)
            ->sortByDesc('created_at')
            ->take($limite)
            ->values();

        return response()->json($feed);
    }
}
