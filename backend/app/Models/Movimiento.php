<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrada o salida manual de mercancía (compra/reabastecimiento, traslado a
 * otra sede, daño, pérdida, ajuste de conteo). Las salidas por venta NO
 * generan un registro acá -- ya quedan trazadas en la tabla "ventas"; ver
 * ReporteController::cuadre() para el resumen que junta ambas fuentes.
 * No usa soft deletes: es un libro de movimientos, no un CRUD -- borrar un
 * registro (solo admin) revierte el efecto sobre el stock, no lo oculta.
 */
class Movimiento extends Model
{
    use HasFactory;

    protected $table = 'movimientos';

    protected $fillable = [
        'inventario_id',
        'sucursal_id',
        'tipo',
        'cantidad',
        'motivo',
        'user_id',
        'fecha',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'fecha' => 'date',
        ];
    }

    public function inventario(): BelongsTo
    {
        return $this->belongsTo(Inventario::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
