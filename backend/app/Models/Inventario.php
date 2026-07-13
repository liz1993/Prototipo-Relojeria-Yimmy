<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Inventario extends Model
{
    use HasFactory, SoftDeletes;

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

    public function getFotoUrlAttribute(): ?string
    {
        return $this->foto ? Storage::disk('public')->url($this->foto) : null;
    }

    public function scopeStockBajo($query)
    {
        return $query->where('cantidad', '<=', self::UMBRAL_STOCK_BAJO);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }
}
