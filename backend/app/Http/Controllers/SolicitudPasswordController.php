<?php

namespace App\Http\Controllers;

use App\Models\SolicitudPassword;
use App\Models\User;
use Illuminate\Http\Request;

/** Flujo de "olvidé mi contraseña": el usuario avisa, el admin lo atiende a mano. Ver SolicitudPassword y UserController. */
class SolicitudPasswordController extends Controller
{
    /**
     * Pública a propósito. Siempre responde el mismo mensaje exista o no el
     * usuario, para no revelar qué nombres de usuario son válidos.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('username', $data['username'])->first();

        if ($user) {
            $yaPendiente = SolicitudPassword::where('user_id', $user->id)->whereNull('atendida_en')->exists();
            if (! $yaPendiente) {
                SolicitudPassword::create(['user_id' => $user->id]);
            }
        }

        return response()->json([
            'message' => 'Si el usuario existe, quedó avisado el administrador para que te contacte.',
        ]);
    }

    /** Lista de solicitudes pendientes (solo admin), para la pantalla de Mantenimiento. */
    public function index()
    {
        return SolicitudPassword::with('user:id,name,username')
            ->whereNull('atendida_en')
            ->orderBy('created_at')
            ->get();
    }

    /** Marca una solicitud como resuelta, después de que el admin ya cambió la contraseña desde Usuarios. */
    public function atender(SolicitudPassword $solicitud)
    {
        $solicitud->update(['atendida_en' => now()]);

        return response()->json($solicitud);
    }
}
