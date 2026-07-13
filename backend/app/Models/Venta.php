<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    public function inventario(): BelongsTo
    {
        return $this->belongsTo(Inventario::class);
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
