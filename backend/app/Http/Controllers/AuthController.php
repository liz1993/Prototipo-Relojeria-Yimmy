<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Login, registro y sesión. Usa Sanctum con tokens (no cookies), porque
 * el frontend estático y el backend corren en puertos distintos.
 */
class AuthController extends Controller
{
    /** Recibe email + password, devuelve un token de Sanctum si son válidos. */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Credenciales incorrectas.'], 401);
        }

        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user),
        ]);
    }

    /** Auto-registro público. Siempre crea un usuario "empleado" -- para ser admin hace falta que otro admin lo cree/ascienda desde Usuarios. */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:3'],
            'sucursal_id' => ['required', 'integer', 'exists:sucursales,id'],
        ]);

        $user = User::create([
            'name' => $data['name'] ?: $data['username'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'tipo' => 'empleado',
            'sucursal_id' => $data['sucursal_id'],
        ]);

        return response()->json([
            'user' => $this->formatUser($user),
        ], 201);
    }

    /** Revoca el token actual (cierra sesión solo en este dispositivo). */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /** Devuelve el usuario autenticado -- usado para restaurar la sesión al recargar la página. */
    public function me(Request $request)
    {
        return response()->json($this->formatUser($request->user()));
    }

    /** Da forma consistente a la info del usuario que se manda al frontend. */
    private function formatUser(User $user): array
    {
        $user->loadMissing('sucursal:id,nombre');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'tipo' => $user->tipo,
            'sucursal' => $user->sucursal ? ['id' => $user->sucursal->id, 'nombre' => $user->sucursal->nombre] : null,
        ];
    }
}
