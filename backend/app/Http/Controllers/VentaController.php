<?php

namespace App\Http\Controllers;

use App\Models\Inventario;
use App\Models\Venta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ventas, con o sin producto de Inventario asociado. Cuando sí hay producto
 * asociado, store()/destroy() mueven el stock dentro de una transacción
 * (DB::transaction) para que la venta y el descuento de inventario nunca
 * queden a medias si algo falla.
 */
class VentaController extends Controller
{
    /** Lista ventas, filtradas por sucursal (forzado para empleado, opcional para admin). */
    public function index(Request $request)
    {
        $query = Venta::with('user:id,name,username')->orderByDesc('id');
        $user = $request->user();

        if ($user->tipo !== 'admin') {
            $query->where('sucursal_id', $user->sucursal_id);
        } elseif ($request->filled('sucursal_id')) {
            $query->where('sucursal_id', $request->query('sucursal_id'));
        }

        return $query->get();
    }

    /**
     * Registra una venta. Si viene ligada a un producto de Inventario,
     * valida que haya stock suficiente y lo descuenta (con lockForUpdate,
     * para que dos ventas simultáneas no descuenten sobre un stock ya
     * caducado). Si "inventario_id" viene vacío, es una venta libre y no
     * toca el inventario.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $rules = [
            'producto' => ['required', 'string', 'max:255'],
            'cantidad' => ['nullable', 'integer', 'min:1'],
            'valor' => ['nullable', 'numeric', 'min:0'],
            'fecha' => ['nullable', 'date'],
            'inventario_id' => ['nullable', 'integer', 'exists:inventario,id'],
        ];
        if ($user->tipo === 'admin') {
            $rules['sucursal_id'] = ['required', 'integer', 'exists:sucursales,id'];
        }

        $data = $request->validate($rules);

        $data['sucursal_id'] = (int) ($user->tipo === 'admin' ? $data['sucursal_id'] : $user->sucursal_id);
        $data['user_id'] = $user->id;
        $data['fecha'] = $data['fecha'] ?? now()->toDateString();

        $venta = DB::transaction(function () use ($data) {
            if (! empty($data['inventario_id'])) {
                $item = Inventario::lockForUpdate()->findOrFail($data['inventario_id']);

                if ((int) $item->sucursal_id !== $data['sucursal_id']) {
                    throw ValidationException::withMessages([
                        'inventario_id' => 'El producto seleccionado no pertenece a esta sucursal.',
                    ]);
                }

                $cantidad = $data['cantidad'] ?? 1;
                if ($item->cantidad < $cantidad) {
                    throw ValidationException::withMessages([
                        'cantidad' => "Stock insuficiente: solo quedan {$item->cantidad} unidades de \"{$item->descripcion}\".",
                    ]);
                }

                $item->decrement('cantidad', $cantidad);
            }

            return Venta::create($data);
        });

        return response()->json($venta, 201);
    }

    /** Edita los datos de una venta. A propósito NO toca el stock -- si hay que corregirlo, se hace directo en Inventario. */
    public function update(Request $request, Venta $venta)
    {
        $data = $request->validate([
            'producto' => ['required', 'string', 'max:255'],
            'cantidad' => ['nullable', 'integer', 'min:0'],
            'valor' => ['nullable', 'numeric', 'min:0'],
            'fecha' => ['nullable', 'date'],
        ]);

        $venta->update($data);

        return response()->json($venta);
    }

    /** Borra (soft delete) la venta y devuelve el stock descontado al inventario. */
    public function destroy(Venta $venta)
    {
        DB::transaction(function () use ($venta) {
            if ($venta->inventario_id) {
                Inventario::where('id', $venta->inventario_id)
                    ->increment('cantidad', $venta->cantidad ?? 1);
            }

            $venta->delete();
        });

        return response()->json(null, 204);
    }
}
