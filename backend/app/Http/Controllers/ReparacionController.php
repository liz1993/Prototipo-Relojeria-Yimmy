<?php

namespace App\Http\Controllers;

use App\Models\Reparacion;
use Illuminate\Http\Request;

/**
 * Reparaciones. Es el único recurso donde un empleado puede escribir sobre
 * un registro que no creó él mismo (para mover el estado / dejar
 * observaciones) -- por eso update() valida "a mano" la sucursal del
 * registro (ver el comentario ahí abajo).
 */
class ReparacionController extends Controller
{
    /** Flujo de estados por el que pasa una reparación, en orden. */
    public const ESTADOS = ['pendiente', 'en_proceso', 'listo', 'entregado'];

    /** Lista reparaciones. "buscar" filtra por nombre de cliente (parcial) o por id exacto si es numérico -- lo usa también Consulta de Estado. */
    public function index(Request $request)
    {
        $query = Reparacion::with(['user:id,name,username', 'sucursal:id,nombre'])->orderByDesc('id');
        $user = $request->user();

        if ($user->tipo !== 'admin') {
            $query->where('sucursal_id', $user->sucursal_id);
        } elseif ($request->filled('sucursal_id')) {
            $query->where('sucursal_id', $request->query('sucursal_id'));
        }

        if ($buscar = $request->query('buscar')) {
            $query->where(function ($sub) use ($buscar) {
                $sub->where('cliente', 'like', '%'.$buscar.'%');
                if (is_numeric($buscar)) {
                    $sub->orWhere('id', (int) $buscar);
                }
            });
        }

        return $query->get();
    }

    /** Crea una reparación nueva, siempre arranca en estado "pendiente". */
    public function store(Request $request)
    {
        $user = $request->user();

        $rules = [
            'cliente' => ['required', 'string', 'max:255'],
            'cedula' => ['nullable', 'string', 'max:50'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'modelo' => ['nullable', 'string', 'max:255'],
            'valor_total' => ['nullable', 'numeric', 'min:0'],
            'abono' => ['nullable', 'numeric', 'min:0'],
            'fecha' => ['nullable', 'date'],
            'foto' => ['nullable', 'image', 'max:4096'],
            'observaciones' => ['nullable', 'string'],
        ];
        if ($user->tipo === 'admin') {
            $rules['sucursal_id'] = ['required', 'integer', 'exists:sucursales,id'];
        }

        $data = $request->validate($rules);

        if ($request->hasFile('foto')) {
            $data['foto'] = $request->file('foto')->store('reparaciones', 'public');
        }

        $data['sucursal_id'] = $user->tipo === 'admin' ? $data['sucursal_id'] : $user->sucursal_id;
        $data['user_id'] = $user->id;
        $data['estado'] = 'pendiente';

        $reparacion = Reparacion::create($data);

        return response()->json($reparacion, 201);
    }

    /**
     * Cualquier usuario autenticado puede mover el "estado" de una reparación
     * y editar sus observaciones. Solo el admin puede editar el resto del
     * registro (cliente, modelo, valores, foto).
     */
    public function update(Request $request, Reparacion $reparacion)
    {
        $user = $request->user();
        $esAdmin = $user->tipo === 'admin';

        // Un empleado solo puede mover el estado de reparaciones de su propia
        // sucursal — sin este chequeo, el route-model binding no valida a
        // quién pertenece $reparacion y cualquier empleado autenticado podía
        // cambiar el estado de una reparación de otra sucursal con solo
        // adivinar/conocer su id.
        if (! $esAdmin && $reparacion->sucursal_id !== $user->sucursal_id) {
            abort(404);
        }

        $rules = [
            'estado' => ['required', 'in:'.implode(',', self::ESTADOS)],
            // A diferencia del resto de campos (solo admin), observaciones la puede
            // escribir cualquiera que atienda la reparación — quien la revisa día a
            // día suele ser el empleado, no el dueño.
            'observaciones' => ['nullable', 'string'],
        ];

        if ($esAdmin) {
            $rules += [
                'cliente' => ['required', 'string', 'max:255'],
                'cedula' => ['nullable', 'string', 'max:50'],
                'telefono' => ['nullable', 'string', 'max:50'],
                'modelo' => ['nullable', 'string', 'max:255'],
                'valor_total' => ['nullable', 'numeric', 'min:0'],
                'abono' => ['nullable', 'numeric', 'min:0'],
                'fecha' => ['nullable', 'date'],
                'foto' => ['nullable', 'image', 'max:4096'],
            ];
        }

        $data = $request->validate($rules);

        if ($esAdmin && $request->hasFile('foto')) {
            $data['foto'] = $request->file('foto')->store('reparaciones', 'public');
        }

        $reparacion->update($data);

        return response()->json($reparacion);
    }

    /** Borrado suave (solo admin) -- pasa a la papelera. */
    public function destroy(Reparacion $reparacion)
    {
        $reparacion->delete();

        return response()->json(null, 204);
    }
}
