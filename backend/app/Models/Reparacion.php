<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

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

    public function getSaldoAttribute(): string
    {
        return bcsub((string) $this->valor_total, (string) $this->abono, 2);
    }

    public function getFotoUrlAttribute(): ?string
    {
        return $this->foto ? Storage::disk('public')->url($this->foto) : null;
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
