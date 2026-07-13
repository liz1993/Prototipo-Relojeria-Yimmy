<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return User::with('sucursal:id,nombre')->orderBy('name')->get();
    }

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
