<?php

namespace App\Http\Controllers;

use App\Models\Inventario;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD de productos del catálogo. Todo aquí respeta la sucursal: un
 * empleado solo ve/crea en la suya, un admin ve todas y elige a cuál
 * escribir.
 */
class InventarioController extends Controller
{
    /** Lista el inventario, filtrado por sucursal (forzado para empleado, opcional para admin). */
    public function index(Request $request)
    {
        $query = Inventario::query();
        $user = $request->user();

        if ($user->tipo !== 'admin') {
            $query->where('sucursal_id', $user->sucursal_id);
        } elseif ($request->filled('sucursal_id')) {
            $query->where('sucursal_id', $request->query('sucursal_id'));
        }

        $items = $query->orderBy('descripcion')->get();

        // El costo es información de margen sensible: se oculta a empleados
        // a nivel de API (no solo en la UI), para que no quede expuesto en la
        // pestaña de red del navegador.
        if ($user->tipo !== 'admin') {
            $items->makeHidden('costo');
        }

        return $items;
    }

    /** Crea un producto (admin, ya que exige sucursal_id explícito). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'sucursal_id' => ['required', 'integer', 'exists:sucursales,id'],
            'codigo' => ['required', 'string', 'max:255', Rule::unique('inventario')->where(fn ($q) => $q->where('sucursal_id', $request->input('sucursal_id')))],
            'descripcion' => ['required', 'string', 'max:255'],
            'cantidad' => ['nullable', 'integer', 'min:0'],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'costo' => ['nullable', 'numeric', 'min:0'],
            'foto' => ['nullable', 'image', 'max:4096'],
        ]);

        if ($request->hasFile('foto')) {
            $data['foto'] = $request->file('foto')->store('inventario', 'public');
        }

        $item = Inventario::create($data);

        return response()->json($item, 201);
    }

    /** Edita un producto existente (mismas reglas que store). */
    public function update(Request $request, Inventario $inventario)
    {
        $data = $request->validate([
            'sucursal_id' => ['required', 'integer', 'exists:sucursales,id'],
            'codigo' => ['required', 'string', 'max:255', Rule::unique('inventario')->ignore($inventario->id)->where(fn ($q) => $q->where('sucursal_id', $request->input('sucursal_id')))],
            'descripcion' => ['required', 'string', 'max:255'],
            'cantidad' => ['nullable', 'integer', 'min:0'],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'costo' => ['nullable', 'numeric', 'min:0'],
            'foto' => ['nullable', 'image', 'max:4096'],
        ]);

        if ($request->hasFile('foto')) {
            $data['foto'] = $request->file('foto')->store('inventario', 'public');
        }

        $inventario->update($data);

        return response()->json($inventario);
    }

    /** Borrado suave: el producto pasa a la papelera, no desaparece de la base. */
    public function destroy(Inventario $inventario)
    {
        $inventario->delete();

        return response()->json(null, 204);
    }
}
