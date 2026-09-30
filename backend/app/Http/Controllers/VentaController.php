<?php

namespace App\Http\Controllers;

use App\Models\Inventario;
use App\Models\Sucursal;
use App\Models\Venta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ventas, con o sin producto de Inventario asociado. Cuando sí hay producto
 * asociado, store()/destroy() mueven el stock dentro de una transacción
 * (DB::transaction) para que la venta y el descuento de inventario nunca
 * queden a medias si algo falla. También respeta "modulo" (relojería/
 * joyería, ver Sucursal::permiteModulo): una venta ligada a un producto
 * hereda su módulo (no se puede elegir otro); una venta libre lo pide.
 */
class VentaController extends Controller
{
    /**
     * Lista ventas, filtradas por sucursal (forzado para empleado, opcional
     * para admin), por módulo, y opcionalmente por empleado que la registró
     * y/o rango de fechas (sobre "fecha", la fecha real de la venta -- no
     * "created_at" -- para que coincida con Cierres Diarios y Reportes).
     */
    public function index(Request $request)
    {
        $query = Venta::with('user:id,name,username')->where('modulo', $request->query('modulo', 'relojeria'))->orderByDesc('id');
        $user = $request->user();

        if ($user->tipo !== 'admin') {
            $query->where('sucursal_id', $user->sucursal_id);
        } elseif ($request->filled('sucursal_id')) {
            $query->where('sucursal_id', $request->query('sucursal_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }
        if ($request->filled('desde')) {
            $query->whereDate('fecha', '>=', $request->query('desde'));
        }
        if ($request->filled('hasta')) {
            $query->whereDate('fecha', '<=', $request->query('hasta'));
        }

        return $query->get();
    }

    /**
     * Registra una venta. Si viene ligada a un producto de Inventario,
     * valida que haya stock suficiente y lo descuenta (con lockForUpdate,
     * para que dos ventas simultáneas no descuenten sobre un stock ya
     * caducado). Si "inventario_id" viene vacío, es una venta libre y no
     * toca el inventario.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $rules = [
            'producto' => ['required', 'string', 'max:255'],
            'cantidad' => ['nullable', 'integer', 'min:1'],
            'valor' => ['nullable', 'numeric', 'min:0'],
            'fecha' => ['nullable', 'date'],
            'tipo' => ['nullable', 'in:venta,arreglo'],
            'metodo_pago' => ['nullable', 'in:efectivo,nequi'],
            'observaciones' => ['nullable', 'string'],
            // whereNull('deleted_at'): sin esto, un producto borrado (papelera) pasa
            // la validación pero el findOrFail() de abajo lo rechaza igual -- solo
            // que como un 404 crudo en vez de este 422 con mensaje claro.
            'inventario_id' => ['nullable', 'integer', Rule::exists('inventario', 'id')->whereNull('deleted_at')],
        ];
        if ($user->tipo === 'admin') {
            $rules['sucursal_id'] = ['required', 'integer', 'exists:sucursales,id'];
        }
        $rules['modulo'] = ['nullable', 'in:relojeria,joyeria'];

        $data = $request->validate($rules);
        // Una venta libre (sin inventario_id) usa el módulo que mandaron (o
        // "relojeria" por defecto); una ligada a un producto lo hereda de
        // este más abajo, pisando lo que se haya mandado acá.
        $data['modulo'] = $data['modulo'] ?? 'relojeria';
        $data['tipo'] = $data['tipo'] ?? 'venta';
        $data['metodo_pago'] = $data['metodo_pago'] ?? 'efectivo';

        $data['sucursal_id'] = (int) ($user->tipo === 'admin' ? $data['sucursal_id'] : $user->sucursal_id);
        $data['user_id'] = $user->id;
        $data['fecha'] = $data['fecha'] ?? now()->toDateString();

        $venta = DB::transaction(function () use ($data) {
            if (! empty($data['inventario_id'])) {
                $item = Inventario::lockForUpdate()->findOrFail($data['inventario_id']);

                if ((int) $item->sucursal_id !== $data['sucursal_id']) {
                    throw ValidationException::withMessages([
                        'inventario_id' => 'El producto seleccionado no pertenece a esta sucursal.',
                    ]);
                }

                $cantidad = $data['cantidad'] ?? 1;
                if ($item->cantidad < $cantidad) {
                    throw ValidationException::withMessages([
                        'cantidad' => "Stock insuficiente: solo quedan {$item->cantidad} unidades de \"{$item->descripcion}\".",
                    ]);
                }

                $item->decrement('cantidad', $cantidad);
                $data['modulo'] = $item->modulo;
            }

            if (! Sucursal::permiteModulo($data['sucursal_id'], $data['modulo'])) {
                throw ValidationException::withMessages(['modulo' => 'Esta sucursal no tiene habilitado el módulo de Joyería.']);
            }

            return Venta::create($data);
        });

        return response()->json($venta, 201);
    }

    /**
     * Edita los datos de una venta. A propósito NO toca el stock -- si hay que
     * corregirlo, se hace directo en Inventario. Un empleado puede corregir
     * una venta de su propia sucursal (para poder cuadrar caja si se
     * equivocó); de otra sucursal, ni se entera de que existe (404, mismo
     * criterio que MovimientoController).
     */
    public function update(Request $request, Venta $venta)
    {
        $user = $request->user();
        if ($user->tipo !== 'admin' && (int) $venta->sucursal_id !== (int) $user->sucursal_id) {
            abort(404);
        }

        $data = $request->validate([
            'producto' => ['required', 'string', 'max:255'],
            'cantidad' => ['nullable', 'integer', 'min:0'],
            'valor' => ['nullable', 'numeric', 'min:0'],
            'fecha' => ['nullable', 'date'],
            'tipo' => ['nullable', 'in:venta,arreglo'],
            'metodo_pago' => ['nullable', 'in:efectivo,nequi'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $venta->update($data);

        return response()->json($venta);
    }

    /**
     * Borra (soft delete) la venta y devuelve el stock descontado al
     * inventario. Mismo criterio de sucursal que update(): un empleado solo
     * puede borrar ventas de su propia sucursal.
     */
    public function destroy(Request $request, Venta $venta)
    {
        $user = $request->user();
        if ($user->tipo !== 'admin' && (int) $venta->sucursal_id !== (int) $user->sucursal_id) {
            abort(404);
        }

        DB::transaction(function () use ($venta) {
            if ($venta->inventario_id) {
                Inventario::where('id', $venta->inventario_id)
                    ->increment('cantidad', $venta->cantidad ?? 1);
            }

            $venta->delete();
        });

        return response()->json(null, 204);
    }

    /**
     * Descarga en CSV las ventas visibles, con los mismos filtros que
     * index() (módulo, sucursal, empleado, rango de fechas), para llevarlas
     * a Excel.
     */
    public function exportar(Request $request)
    {
        $query = Venta::with(['user:id,name,username', 'sucursal:id,nombre', 'inventario:id,codigo'])
            ->where('modulo', $request->query('modulo', 'relojeria'))
            ->orderBy('fecha');

        if ($request->filled('sucursal_id')) {
            $query->where('sucursal_id', $request->query('sucursal_id'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }
        if ($request->filled('desde')) {
            $query->whereDate('fecha', '>=', $request->query('desde'));
        }
        if ($request->filled('hasta')) {
            $query->whereDate('fecha', '<=', $request->query('hasta'));
        }

        $ventas = $query->get();
        $nombreArchivo = 'ventas_'.now()->format('Y-m-d').'.csv';

        return new StreamedResponse(function () use ($ventas) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: para que Excel detecte UTF-8 y muestre bien los acentos
            // Mismo orden de columnas que la plantilla de importarVentas() (más
            // Sucursal/Registrado por al final, de referencia): así el archivo
            // exportado se puede editar y volver a importar tal cual, sin perder
            // el vínculo con el producto de Inventario.
            fputcsv($out, ['Fecha', 'Producto', 'Código', 'Cantidad', 'Valor', 'Tipo', 'Método de pago', 'Observaciones', 'Sucursal', 'Registrado por']);

            foreach ($ventas as $venta) {
                fputcsv($out, [
                    $venta->fecha->format('Y-m-d'),
                    $venta->producto,
                    $venta->inventario->codigo ?? '',
                    $venta->cantidad,
                    $venta->valor,
                    $venta->tipo,
                    $venta->metodo_pago,
                    $venta->observaciones,
                    $venta->sucursal->nombre ?? '',
                    $venta->user->name ?? '',
                ]);
            }

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nombreArchivo}\"",
        ]);
    }

    /**
     * Importa ventas desde un CSV a una sucursal puntual. Columnas esperadas
     * (por nombre, no por posición): Fecha, Producto, Código (opcional --
     * liga la fila a un producto de Inventario y descuenta su stock, igual
     * que una venta creada a mano con producto del catálogo), Cantidad,
     * Valor, Tipo, Método de pago, Observaciones. Una fila con error (código
     * inexistente, stock insuficiente, fecha inválida) se rechaza sola; el
     * resto del archivo se sigue procesando.
     */
    public function importar(Request $request)
    {
        $data = $request->validate([
            'sucursal_id' => ['required', 'integer', 'exists:sucursales,id'],
            'modulo' => ['nullable', 'in:relojeria,joyeria'],
            'archivo' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $modulo = $data['modulo'] ?? 'relojeria';
        if (! Sucursal::permiteModulo($data['sucursal_id'], $modulo)) {
            throw ValidationException::withMessages(['modulo' => 'Esta sucursal no tiene habilitado el módulo de Joyería.']);
        }

        $handle = fopen($request->file('archivo')->getRealPath(), 'r');
        $encabezado = fgetcsv($handle);
        if ($encabezado === false) {
            fclose($handle);
            throw ValidationException::withMessages(['archivo' => 'El archivo está vacío.']);
        }
        // Se ubican las columnas por nombre (normalizado: sin BOM/espacios, minúsculas)
        // en vez de por posición fija, para que el orden de columnas no importe.
        $encabezado = array_map(fn ($c) => strtolower(trim($c, "\xEF\xBB\xBF \t\n\r\0\x0B")), $encabezado);
        $idx = array_flip($encabezado);
        $col = fn (string ...$nombres) => collect($nombres)->map(fn ($n) => $idx[$n] ?? null)->first(fn ($v) => $v !== null);

        $iFecha = $col('fecha');
        $iProducto = $col('producto');
        $iCodigo = $col('código', 'codigo');
        $iCantidad = $col('cantidad');
        $iValor = $col('valor');
        $iTipo = $col('tipo');
        $iMetodo = $col('método de pago', 'metodo de pago', 'metodo_pago');
        $iObs = $col('observaciones');

        if ($iProducto === null && $iCodigo === null) {
            fclose($handle);
            throw ValidationException::withMessages(['archivo' => 'El CSV debe tener una columna "Producto" o "Código".']);
        }

        $creados = 0;
        $errores = [];
        $fila = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $fila++;
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // línea en blanco (frecuente al final del archivo)
            }

            try {
                DB::transaction(function () use (
                    $row, $fila, $data, $modulo, $request,
                    $iFecha, $iProducto, $iCodigo, $iCantidad, $iValor, $iTipo, $iMetodo, $iObs,
                    &$creados
                ) {
                    $item = null;
                    $codigo = $iCodigo !== null ? trim($row[$iCodigo] ?? '') : '';
                    if ($codigo !== '') {
                        $item = Inventario::lockForUpdate()
                            ->where('sucursal_id', $data['sucursal_id'])
                            ->where('codigo', $codigo)
                            ->first();
                        if (! $item) {
                            throw new \RuntimeException("Fila {$fila}: no existe el código \"{$codigo}\" en esta sucursal.");
                        }
                    }

                    $cantidad = $iCantidad !== null && is_numeric($row[$iCantidad] ?? null) ? (int) $row[$iCantidad] : 1;

                    $producto = $iProducto !== null ? trim((string) ($row[$iProducto] ?? '')) : '';
                    if ($producto === '') {
                        $producto = $item->descripcion ?? '';
                    }
                    if ($producto === '') {
                        throw new \RuntimeException("Fila {$fila}: falta el producto (o un código válido).");
                    }

                    $valor = $iValor !== null && is_numeric($row[$iValor] ?? null) ? (float) $row[$iValor] : null;
                    if ($valor === null) {
                        $valor = $item ? $item->precio * $cantidad : 0;
                    }

                    $fecha = now()->toDateString();
                    if ($iFecha !== null && trim($row[$iFecha] ?? '') !== '') {
                        try {
                            $fecha = \Carbon\Carbon::parse(trim($row[$iFecha]))->toDateString();
                        } catch (\Exception $e) {
                            throw new \RuntimeException("Fila {$fila}: la fecha \"{$row[$iFecha]}\" no es válida.");
                        }
                    }

                    $tipo = $iTipo !== null ? strtolower(trim($row[$iTipo] ?? '')) : 'venta';
                    if (! in_array($tipo, ['venta', 'arreglo'], true)) {
                        $tipo = 'venta';
                    }

                    $metodoPago = $iMetodo !== null ? strtolower(trim($row[$iMetodo] ?? '')) : 'efectivo';
                    if (! in_array($metodoPago, ['efectivo', 'nequi'], true)) {
                        $metodoPago = 'efectivo';
                    }

                    if ($item) {
                        if ($item->cantidad < $cantidad) {
                            throw new \RuntimeException("Fila {$fila}: stock insuficiente para \"{$item->descripcion}\" (quedan {$item->cantidad}).");
                        }
                        $item->decrement('cantidad', $cantidad);
                    }

                    Venta::create([
                        'producto' => $producto,
                        'cantidad' => $cantidad,
                        'valor' => $valor,
                        'fecha' => $fecha,
                        'inventario_id' => $item->id ?? null,
                        'user_id' => $request->user()->id,
                        'sucursal_id' => $data['sucursal_id'],
                        'modulo' => $item->modulo ?? $modulo,
                        'tipo' => $tipo,
                        'metodo_pago' => $metodoPago,
                        'observaciones' => $iObs !== null ? trim((string) ($row[$iObs] ?? '')) : null,
                    ]);

                    $creados++;
                });
            } catch (\RuntimeException $e) {
                $errores[] = $e->getMessage();
            }
        }

        fclose($handle);

        return response()->json([
            'creados' => $creados,
            'errores' => $errores,
        ]);
    }
}
