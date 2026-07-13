<?php

namespace App\Http\Controllers;

use App\Models\Inventario;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventarioController extends Controller
{
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

    public function destroy(Inventario $inventario)
    {
        $inventario->delete();

        return response()->json(null, 204);
    }
}
