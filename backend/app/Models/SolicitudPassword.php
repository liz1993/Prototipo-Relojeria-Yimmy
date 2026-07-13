<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un aviso de "olvidé mi contraseña" que un usuario dejó para el admin.
 * No hay recuperación por correo en esta app -- el admin ve la lista de
 * solicitudes pendientes (atendida_en = null) y le cambia la contraseña
 * a mano desde Usuarios, luego marca la solicitud como atendida.
 */
class SolicitudPassword extends Model
{
    protected $table = 'solicitudes_password';

    protected $fillable = [
        'user_id',
        'atendida_en',
    ];

    protected function casts(): array
    {
        return [
            'atendida_en' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
