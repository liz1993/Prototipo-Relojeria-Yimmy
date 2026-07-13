<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sucursal extends Model
{
    use HasFactory;

    protected $table = 'sucursales';

    protected $fillable = [
        'nombre',
        'direccion',
        'telefono',
    ];

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function inventario(): HasMany
    {
        return $this->hasMany(Inventario::class);
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    public function reparaciones(): HasMany
    {
        return $this->hasMany(Reparacion::class);
    }
}
