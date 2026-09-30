<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        $email = $data['username'].'@relojeriajimmy.local';

        // El username ya se validó único arriba, pero el email autogenerado a
        // partir de él podría chocar con el de alguien que se autoregistró
        // (público, vía /register) con ese correo exacto -- sin este chequeo,
        // el create() de abajo tira un 500 crudo en vez de un 422 claro.
        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'username' => 'Ya existe una cuenta con un correo equivalente a este nombre de usuario.',
            ]);
        }

        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $email,
            'password' => Hash::make($data['password']),
            'tipo' => $data['tipo'],
            // A diferencia del autoregistro público (AuthController::register), un
            // usuario creado a mano por un admin ya está vetted -- no necesita
            // pasar por el flujo de aprobación.
            'aprobado' => true,
            'sucursal_id' => $data['tipo'] === 'admin' ? null : $data['sucursal_id'],
        ]);

        return response()->json($user->load('sucursal:id,nombre'), 201);
    }

    /** Aprueba una cuenta creada por autoregistro público, para que pueda iniciar sesión. */
    public function aprobar(User $user)
    {
        $user->update(['aprobado' => true]);

        return response()->json($user->load('sucursal:id,nombre'));
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

        // Si se está degradando al único admin que queda, se bloquea: de lo
        // contrario nadie podría volver a entrar a las pantallas de admin
        // (Usuarios, Sucursales, Reportes, etc.) para deshacer el cambio.
        if ($user->tipo === 'admin' && $data['tipo'] !== 'admin' && User::where('tipo', 'admin')->count() <= 1) {
            throw ValidationException::withMessages([
                'tipo' => 'No se puede quitar el único administrador del sistema.',
            ]);
        }

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
