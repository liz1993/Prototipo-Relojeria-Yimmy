<?php

namespace App\Observers;

use App\Models\RegistroActividad;
use App\Models\Venta;
use Illuminate\Support\Facades\Auth;

/**
 * Escucha los eventos del modelo Venta (no del controlador) para que quede
 * registrado sin importar por dónde se creó/modificó/borró -- así el dueño
 * puede ver en "Actividad" si una empleada corrigió o eliminó una venta
 * después de registrarla, sin depender de que cada lugar del código recuerde
 * llamar a un método de log.
 */
class VentaObserver
{
    public function created(Venta $venta): void
    {
        $this->registrar($venta, 'creado', $this->descripcionBase($venta, 'Venta registrada'));
    }

    public function updated(Venta $venta): void
    {
        $cambios = collect($venta->getChanges())
            ->except(['updated_at'])
            ->map(fn ($valor, $campo) => "{$campo}: \"{$venta->getOriginal($campo)}\" → \"{$valor}\"")
            ->implode(', ');

        if ($cambios === '') {
            return;
        }

        $this->registrar($venta, 'modificado', "Venta #{$venta->id} ({$venta->producto}) modificada — {$cambios}");
    }

    public function deleted(Venta $venta): void
    {
        $this->registrar($venta, 'eliminado', $this->descripcionBase($venta, 'Venta eliminada'));
    }

    private function descripcionBase(Venta $venta, string $prefijo): string
    {
        return "{$prefijo}: {$venta->producto}".($venta->cantidad ? " x{$venta->cantidad}" : '');
    }

    private function registrar(Venta $venta, string $accion, string $descripcion): void
    {
        RegistroActividad::create([
            'user_id' => Auth::id(),
            'sucursal_id' => $venta->sucursal_id,
            'entidad' => 'venta',
            'entidad_id' => $venta->id,
            'accion' => $accion,
            // $venta->modulo puede venir null en memoria si el registro se creó
            // sin mandar "modulo" explícito (ej. un factory de test que confía en
            // el default de la columna): Eloquent no relee ese default después
            // del INSERT, así que sin este fallback quedaría null y violaría el
            // NOT NULL de esta tabla.
            'modulo' => $venta->modulo ?? 'relojeria',
            'descripcion' => $descripcion,
            'monto' => $venta->valor,
        ]);
    }
}
