<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use Illuminate\Http\Request;

class SucursalController extends Controller
{
    public function index()
    {
        return Sucursal::orderBy('nombre')->get();
    }

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
