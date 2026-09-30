<?php

namespace App\Http\Controllers;

use App\Models\Inventario;
use App\Models\Reparacion;
use App\Models\Sucursal;
use App\Models\Venta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Papelera de reciclaje: junta en una sola lista todo lo que se "eliminó"
 * (soft delete) en Inventario, Ventas y Reparaciones, y permite
 * restaurarlo. Solo admin.
 */
class PapeleraController extends Controller
{
    /** Mapa "tipo" (como llega en la URL) -> clase del modelo correspondiente. */
    private const TIPOS = [
        'inventario' => Inventario::class,
        'ventas' => Venta::class,
        'reparaciones' => Reparacion::class,
    ];

    /** Junta los registros borrados de los 3 tipos en una sola lista, más reciente primero. */
    public function index(Request $request)
    {
        $sucursalId = $request->query('sucursal_id');
        $sucursales = Sucursal::pluck('nombre', 'id');

        $items = collect();

        $inventario = Inventario::onlyTrashed()->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))->get();
        foreach ($inventario as $i) {
            $items->push([
                'tipo' => 'inventario',
                'id' => $i->id,
                'descripcion' => $i->codigo.' — '.$i->descripcion,
                'sucursal' => $sucursales[$i->sucursal_id] ?? null,
                'eliminado_el' => $i->deleted_at,
            ]);
        }

        $ventas = Venta::onlyTrashed()->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))->get();
        foreach ($ventas as $v) {
            $items->push([
                'tipo' => 'ventas',
                'id' => $v->id,
                'descripcion' => $v->producto.' — $'.$v->valor,
                'sucursal' => $sucursales[$v->sucursal_id] ?? null,
                'eliminado_el' => $v->deleted_at,
            ]);
        }

        $reparaciones = Reparacion::onlyTrashed()->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))->get();
        foreach ($reparaciones as $r) {
            $items->push([
                'tipo' => 'reparaciones',
                'id' => $r->id,
                'descripcion' => $r->cliente.' — '.($r->modelo ?: 'sin modelo'),
                'sucursal' => $sucursales[$r->sucursal_id] ?? null,
                'eliminado_el' => $r->deleted_at,
            ]);
        }

        return response()->json($items->sortByDesc('eliminado_el')->values());
    }

    /** Devuelve un registro borrado a su estado normal (quita el soft delete). */
    public function restaurar(string $tipo, int $id)
    {
        abort_unless(array_key_exists($tipo, self::TIPOS), 404);

        if ($tipo === 'ventas') {
            return $this->restaurarVenta($id);
        }

        $modelo = self::TIPOS[$tipo];
        $item = $modelo::onlyTrashed()->findOrFail($id);
        $item->restore();

        return response()->json($item);
    }

    /**
     * Restaurar una venta ligada a inventario debe volver a descontar el
     * stock: VentaController::destroy() se lo había devuelto al borrarla, así
     * que sin este paso quedaría "stock fantasma" (la venta activa de nuevo,
     * pero el stock nunca vuelve a bajar). Si ya no alcanza, se bloquea la
     * restauración en vez de dejar el stock en negativo.
     */
    private function restaurarVenta(int $id)
    {
        return DB::transaction(function () use ($id) {
            $venta = Venta::onlyTrashed()->findOrFail($id);

            if ($venta->inventario_id) {
                $item = Inventario::lockForUpdate()->find($venta->inventario_id);
                $cantidad = $venta->cantidad ?? 1;

                if ($item) {
                    if ($item->cantidad < $cantidad) {
                        throw ValidationException::withMessages([
                            'cantidad' => "No se puede restaurar: solo quedan {$item->cantidad} unidades de \"{$item->descripcion}\" en stock.",
                        ]);
                    }

                    $item->decrement('cantidad', $cantidad);
                }
            }

            $venta->restore();

            return response()->json($venta);
        });
    }
}
