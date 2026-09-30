<?php

namespace App\Http\Controllers;

use App\Models\Inventario;
use App\Models\Movimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Entradas y salidas manuales de mercancía (fuera de las ventas, que se
 * registran solas). store()/destroy() mueven el stock del producto dentro
 * de una transacción, igual que VentaController, para que el movimiento y
 * el ajuste de inventario nunca queden a medias si algo falla.
 */
class MovimientoController extends Controller
{
    /** Lista movimientos, filtrados por sucursal (forzado para empleado, opcional para admin), por módulo (vía el producto relacionado) e inventario/tipo si se piden. */
    public function index(Request $request)
    {
        $modulo = $request->query('modulo', 'relojeria');
        $query = Movimiento::with(['user:id,name,username', 'inventario:id,codigo,descripcion'])
            ->whereHas('inventario', fn ($q) => $q->where('modulo', $modulo))
            ->orderByDesc('id');
        $user = $request->user();

        if ($user->tipo !== 'admin') {
            $query->where('sucursal_id', $user->sucursal_id);
        } elseif ($request->filled('sucursal_id')) {
            $query->where('sucursal_id', $request->query('sucursal_id'));
        }

        if ($request->filled('inventario_id')) {
            $query->where('inventario_id', $request->query('inventario_id'));
        }
        if ($request->filled('tipo')) {
            $query->where('tipo', $request->query('tipo'));
        }

        return $query->get();
    }

    /**
     * Registra una entrada o salida y ajusta "cantidad" en Inventario al
     * vuelo (lockForUpdate: mismo motivo que en VentaController, para que
     * dos movimientos simultáneos sobre el mismo producto no se pisen).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $rules = [
            'inventario_id' => ['required', 'integer', Rule::exists('inventario', 'id')->whereNull('deleted_at')],
            'tipo' => ['required', 'in:entrada,salida'],
            'cantidad' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'max:255'],
            'fecha' => ['nullable', 'date'],
        ];

        $data = $request->validate($rules);
        $data['fecha'] = $data['fecha'] ?? now()->toDateString();
        $data['user_id'] = $user->id;

        $movimiento = DB::transaction(function () use ($data, $user) {
            $item = Inventario::lockForUpdate()->findOrFail($data['inventario_id']);

            // Un empleado solo puede mover stock de su propia sucursal -- sin este
            // chequeo, cualquiera con sesión podía ajustar el inventario de otra
            // sede con solo conocer/adivinar su inventario_id.
            if ($user->tipo !== 'admin' && (int) $item->sucursal_id !== (int) $user->sucursal_id) {
                abort(404);
            }

            if ($data['tipo'] === 'salida' && $item->cantidad < $data['cantidad']) {
                throw ValidationException::withMessages([
                    'cantidad' => "Stock insuficiente: solo quedan {$item->cantidad} unidades de \"{$item->descripcion}\".",
                ]);
            }

            $data['sucursal_id'] = $item->sucursal_id;

            if ($data['tipo'] === 'entrada') {
                $item->increment('cantidad', $data['cantidad']);
            } else {
                $item->decrement('cantidad', $data['cantidad']);
            }

            return Movimiento::create($data);
        });

        return response()->json($movimiento->load('inventario:id,codigo,descripcion'), 201);
    }

    /** Borra el movimiento y revierte su efecto sobre el stock (solo admin). */
    public function destroy(Movimiento $movimiento)
    {
        DB::transaction(function () use ($movimiento) {
            $item = Inventario::lockForUpdate()->find($movimiento->inventario_id);

            // El producto puede haber sido borrado (papelera) después del
            // movimiento -- si ya no existe, no hay stock que revertir.
            if ($item) {
                if ($movimiento->tipo === 'entrada') {
                    // Una entrada revertida no puede dejar el stock en negativo
                    // (pasaría si ya se vendió mercancía que "entró" por error).
                    if ($item->cantidad < $movimiento->cantidad) {
                        throw ValidationException::withMessages([
                            'cantidad' => "No se puede revertir: el stock actual ({$item->cantidad}) es menor a la cantidad del movimiento.",
                        ]);
                    }
                    $item->decrement('cantidad', $movimiento->cantidad);
                } else {
                    $item->increment('cantidad', $movimiento->cantidad);
                }
            }

            $movimiento->delete();
        });

        return response()->json(null, 204);
    }
}
