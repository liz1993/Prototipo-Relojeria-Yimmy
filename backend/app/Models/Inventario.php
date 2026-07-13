<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Un producto del catálogo (una sucursal específica). "precio" es lo que
 * paga el cliente; "costo" es el precio de compra y es información
 * sensible de margen -- se oculta a empleados desde el controlador, no
 * solo en la vista. Usa soft deletes: "eliminar" un producto no lo borra
 * de la base, solo lo marca como borrado (ver PapeleraController).
 */
class Inventario extends Model
{
    use HasFactory, SoftDeletes;

    /** A partir de esta cantidad (inclusive) se considera "stock bajo". */
    public const UMBRAL_STOCK_BAJO = 5;

    protected $table = 'inventario';

    protected $fillable = [
        'codigo',
        'descripcion',
        'cantidad',
        'precio',
        'costo',
        'foto',
        'sucursal_id',
    ];

    // "foto_url" no es una columna real: se calcula al vuelo (ver
    // getFotoUrlAttribute) y se agrega igual a cada respuesta JSON.
    protected $appends = [
        'foto_url',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'precio' => 'decimal:2',
            'costo' => 'decimal:2',
        ];
    }

    /** Arma la URL pública completa a partir de la ruta guardada en "foto". */
    public function getFotoUrlAttribute(): ?string
    {
        return $this->foto ? Storage::disk('public')->url($this->foto) : null;
    }

    /** Filtro reusable: Inventario::stockBajo()->get() trae solo lo que se está agotando. */
    public function scopeStockBajo($query)
    {
        return $query->where('cantidad', '<=', self::UMBRAL_STOCK_BAJO);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }
}
