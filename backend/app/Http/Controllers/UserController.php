<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Gestión de usuarios (solo admin): crear cuentas, ascender a admin,
 * cambiar sucursal o resetear contraseña. Es también el mecanismo real de
 * "recuperar contraseña" de esta app -- no hay envío de correo, el admin
 * cambia la contraseña acá mismo cuando alguien la olvida.
 */
class UserController extends Controller
{
    public function index()
    {
        return User::with('sucursal:id,nombre')->orderBy('name')->get();
    }

    /** Crea un usuario nuevo. Si es admin, sucursal_id se fuerza a null; si es empleado, es obligatorio. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'password' => ['required', 'string', 'min:3'],
            'tipo' => ['required', Rule::in(['admin', 'empleado'])],
            'sucursal_id' => ['required_if:tipo,empleado', 'nullable', 'integer', 'exists:sucursales,id'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['username'].'@relojeriajimmy.local',
            'password' => Hash::make($data['password']),
            'tipo' => $data['tipo'],
            'sucursal_id' => $data['tipo'] === 'admin' ? null : $data['sucursal_id'],
        ]);

        return response()->json($user->load('sucursal:id,nombre'), 201);
    }

    /** Edita un usuario. La contraseña solo cambia si se manda una nueva (nullable). */
    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:3'],
            'tipo' => ['required', Rule::in(['admin', 'empleado'])],
            'sucursal_id' => ['required_if:tipo,empleado', 'nullable', 'integer', 'exists:sucursales,id'],
        ]);

        $user->name = $data['name'];
        $user->username = $data['username'];
        $user->tipo = $data['tipo'];
        $user->sucursal_id = $data['tipo'] === 'admin' ? null : $data['sucursal_id'];
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return response()->json($user->load('sucursal:id,nombre'));
    }
}
