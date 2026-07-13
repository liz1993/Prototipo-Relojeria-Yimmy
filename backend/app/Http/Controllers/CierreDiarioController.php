<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Totales de ventas por día ("cierre de caja"). No hay nada que guardar
 * a mano: todo se calcula agrupando las ventas ya existentes, así que
 * nunca queda desactualizado.
 */
class CierreDiarioController extends Controller
{
    /**
     * Totales de ventas agrupados por día (no hay "cierre" que se guarde a mano:
     * se calcula al vuelo sobre las ventas ya registradas).
     */
    public function index(Request $request)
    {
        $query = Venta::query();
        $this->aplicarFiltros($query, $request);

        // Alias "dia" (no "fecha") a propósito: el cast 'fecha' => 'date' del
        // modelo Venta convertiría el valor a un datetime completo si se
        // llamara igual que la columna, rompiendo el "YYYY-MM-DD" plano que
        // el frontend necesita para armar la ruta de detalle.
        return $query
            ->selectRaw('fecha as dia, COUNT(*) as cantidad_ventas, SUM(valor) as total')
            ->groupBy('fecha')
            ->orderByDesc('fecha')
            ->get();
    }

    /** Ventas de un día puntual, con detalle de cada una (quién la registró, en qué sucursal). */
    public function detalle(Request $request, string $fecha)
    {
        $query = Venta::with(['user:id,name,username', 'sucursal:id,nombre'])
            ->whereDate('fecha', $fecha);
        $this->aplicarFiltros($query, $request);

        return $query->orderBy('created_at')->get();
    }

    /** Descarga un CSV con el detalle de ventas de un rango de fechas, más totales por día y total general. */
    public function exportar(Request $request)
    {
        $data = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
        ]);

        $query = Venta::with(['user:id,name,username', 'sucursal:id,nombre'])
            ->whereDate('fecha', '>=', $data['desde'])
            ->whereDate('fecha', '<=', $data['hasta']);
        if (! empty($data['sucursal_id'])) {
            $query->where('sucursal_id', $data['sucursal_id']);
        }
        $ventas = $query->orderBy('fecha')->orderBy('created_at')->get();

        $nombreArchivo = "ventas_{$data['desde']}_a_{$data['hasta']}.csv";

        return new StreamedResponse(function () use ($ventas) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: para que Excel detecte UTF-8 y muestre bien los acentos
            fputcsv($out, ['Fecha', 'Sucursal', 'Producto', 'Cantidad', 'Valor', 'Registrado por']);

            $totalPorDia = [];
            foreach ($ventas as $venta) {
                $fecha = optional($venta->fecha)->toDateString();
                fputcsv($out, [
                    $fecha,
                    $venta->sucursal->nombre ?? '',
                    $venta->producto,
                    $venta->cantidad ?? '',
                    $venta->valor ?? 0,
                    $venta->user->username ?? '',
                ]);
                $totalPorDia[$fecha] = ($totalPorDia[$fecha] ?? 0) + (float) $venta->valor;
            }

            fputcsv($out, []);
            fputcsv($out, ['Totales por día']);
            foreach ($totalPorDia as $fecha => $total) {
                fputcsv($out, [$fecha, '', '', '', number_format($total, 2, '.', '')]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Total del periodo', '', '', '', number_format(array_sum($totalPorDia), 2, '.', '')]);

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nombreArchivo}\"",
        ]);
    }

    /** Filtro común a index/detalle: sucursal (forzada para empleado) y rango de fechas opcional. */
    private function aplicarFiltros(Builder $query, Request $request): void
    {
        $user = $request->user();

        if ($user->tipo !== 'admin') {
            $query->where('sucursal_id', $user->sucursal_id);
        } elseif ($request->filled('sucursal_id')) {
            $query->where('sucursal_id', $request->query('sucursal_id'));
        }

        if ($request->filled('desde')) {
            $query->whereDate('fecha', '>=', $request->query('desde'));
        }
        if ($request->filled('hasta')) {
            $query->whereDate('fecha', '<=', $request->query('hasta'));
        }
    }
}
