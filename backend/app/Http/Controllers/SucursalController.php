<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use Illuminate\Http\Request;

/**
 * CRUD de sucursales. index() es público (se necesita antes del login,
 * para el selector del formulario de registro); store/update quedan
 * detrás del middleware "admin" en las rutas. A propósito no hay
 * destroy(): borrar una sucursal dejaría huérfanos sus productos/ventas/
 * reparaciones históricas, así que se renombra en vez de eliminarla.
 */
class SucursalController extends Controller
{
    public function index()
    {
        return Sucursal::orderBy('nombre')->get();
    }

    /** Crea una sucursal nueva. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
        ]);

        $sucursal = Sucursal::create($data);

        return response()->json($sucursal, 201);
    }

    /** Edita nombre/dirección/teléfono de una sucursal existente. */
    public function update(Request $request, Sucursal $sucursal)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
        ]);

        $sucursal->update($data);

        return response()->json($sucursal);
    }
}
