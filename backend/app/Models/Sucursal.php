<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un punto de venta físico de la relojería. inventario/ventas/reparaciones/
 * usuarios quedan todos "amarrados" a una sucursal para separar los datos
 * de cada punto. No tiene endpoint de borrado (a propósito, ver
 * SucursalController): se renombra en vez de eliminar, para no dejar
 * huérfanos los registros históricos que ya la referencian.
 */
class Sucursal extends Model
{
    use HasFactory;

    // Eloquent adivinaría "sucursals" (plural en inglés) sin esto.
    protected $table = 'sucursales';

    protected $fillable = [
        'nombre',
        'direccion',
        'telefono',
        'joyeria_habilitada',
    ];

    protected function casts(): array
    {
        return [
            'joyeria_habilitada' => 'boolean',
        ];
    }

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

    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class);
    }

    /** Relojería siempre está permitida; Joyería solo si la sucursal la tiene habilitada. */
    public static function permiteModulo(int $sucursalId, string $modulo): bool
    {
        if ($modulo === 'relojeria') {
            return true;
        }

        return (bool) static::where('id', $sucursalId)->value('joyeria_habilitada');
    }
}
