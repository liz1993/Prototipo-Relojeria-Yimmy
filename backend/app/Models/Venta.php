<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una venta registrada. "inventario_id" es opcional: si viene, la venta
 * está ligada a un producto del catálogo (y descontó stock al crearse,
 * ver VentaController@store); si no, es una "venta libre" (servicio o
 * algo que no se lleva en Inventario). Usa soft deletes.
 */
class Venta extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ventas';

    protected $fillable = [
        'producto',
        'cantidad',
        'valor',
        'fecha',
        'inventario_id',
        'user_id',
        'sucursal_id',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'valor' => 'decimal:2',
            'fecha' => 'date',
        ];
    }

    /** El producto de Inventario vendido, si la venta está ligada a uno. */
    public function inventario(): BelongsTo
    {
        return $this->belongsTo(Inventario::class);
    }

    /** Quién registró la venta. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }
}
