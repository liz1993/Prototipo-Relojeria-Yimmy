<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Un reloj en reparación. "estado" avanza pendiente -> en_proceso -> listo
 * -> entregado (ver ReparacionController::ESTADOS). "saldo" y "foto_url"
 * no se guardan en la base: se calculan cada vez que se piden (más abajo),
 * así siempre quedan consistentes con valor_total/abono/foto. Usa soft deletes.
 */
class Reparacion extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'reparaciones';

    protected $fillable = [
        'cliente',
        'telefono',
        'modelo',
        'valor_total',
        'abono',
        'fecha',
        'foto',
        'estado',
        'observaciones',
        'user_id',
        'sucursal_id',
    ];

    protected $appends = [
        'saldo',
        'foto_url',
    ];

    protected function casts(): array
    {
        return [
            'valor_total' => 'decimal:2',
            'abono' => 'decimal:2',
            'fecha' => 'date',
        ];
    }

    /** Lo que falta por pagar: valor_total - abono (con precisión decimal exacta). */
    public function getSaldoAttribute(): string
    {
        return bcsub((string) $this->valor_total, (string) $this->abono, 2);
    }

    /** Arma la URL pública completa a partir de la ruta guardada en "foto". */
    public function getFotoUrlAttribute(): ?string
    {
        return $this->foto ? Storage::disk('public')->url($this->foto) : null;
    }

    /** Quién registró la reparación. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }
}
