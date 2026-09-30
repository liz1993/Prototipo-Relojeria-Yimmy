<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un evento de auditoría (crear/modificar/eliminar) sobre una entidad del
 * sistema (por ahora solo Venta). Se guarda tal cual pasó -- no se actualiza
 * ni se borra nunca, así que refleja historia real, no el estado actual.
 */
class RegistroActividad extends Model
{
    protected $table = 'registros_actividad';

    protected $fillable = [
        'user_id',
        'sucursal_id',
        'entidad',
        'entidad_id',
        'accion',
        'modulo',
        'descripcion',
        'monto',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }
}
