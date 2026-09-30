<?php

namespace App\Http\Controllers;

use App\Models\Inventario;
use App\Models\Sucursal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CRUD de productos del catálogo. Todo aquí respeta la sucursal: un
 * empleado solo ve/crea en la suya, un admin ve todas y elige a cuál
 * escribir. También respeta el "módulo" (relojería/joyería, ver
 * Sucursal::permiteModulo): son dos rubros que no se mezclan -- cada
 * producto pertenece a uno solo, y joyería únicamente existe en las
 * sucursales que la tienen habilitada.
 */
class InventarioController extends Controller
{
    /** Lista el inventario, filtrado por sucursal (forzado para empleado, opcional para admin) y por módulo. */
    public function index(Request $request)
    {
        $query = Inventario::query()->where('modulo', $request->query('modulo', 'relojeria'));
        $user = $request->user();

        if ($user->tipo !== 'admin') {
            $query->where('sucursal_id', $user->sucursal_id);
        } elseif ($request->filled('sucursal_id')) {
            $query->where('sucursal_id', $request->query('sucursal_id'));
        }

        $items = $query->orderBy('descripcion')->get();

        // El costo es información de margen sensible: se oculta a empleados
        // a nivel de API (no solo en la UI), para que no quede expuesto en la
        // pestaña de red del navegador.
        if ($user->tipo !== 'admin') {
            $items->makeHidden('costo');
        }

        return $items;
    }

    /**
     * Consulta de disponibilidad entre sucursales: para cuando un empleado
     * necesita saber si otra sede tiene un reloj/repuesto para pasárselo.
     * A diferencia de index(), no está restringido a la sucursal propia,
     * pero sí devuelve solo lo mínimo (sin costo, sin resto del catálogo
     * ajeno) -- es una búsqueda puntual, no un listado completo.
     */
    public function disponibilidad(Request $request)
    {
        $data = $request->validate([
            'buscar' => ['required', 'string', 'min:2'],
            'modulo' => ['nullable', 'in:relojeria,joyeria'],
        ]);

        return Inventario::with('sucursal:id,nombre')
            ->where('modulo', $data['modulo'] ?? 'relojeria')
            ->where(function ($query) use ($data) {
                $query->where('descripcion', 'like', '%'.$data['buscar'].'%')
                    ->orWhere('codigo', 'like', '%'.$data['buscar'].'%');
            })
            ->where('cantidad', '>', 0)
            ->orderBy('descripcion')
            ->get(['id', 'codigo', 'descripcion', 'cantidad', 'precio', 'sucursal_id']);
    }

    /** Crea un producto (admin, ya que exige sucursal_id explícito). El código lo genera el sistema, ver comentario más abajo. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'sucursal_id' => ['required', 'integer', 'exists:sucursales,id'],
            'modulo' => ['nullable', 'in:relojeria,joyeria'],
            'descripcion' => ['required', 'string', 'max:255'],
            'cantidad' => ['nullable', 'integer', 'min:0'],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'costo' => ['nullable', 'numeric', 'min:0'],
            'foto' => ['nullable', 'image', 'max:4096'],
        ]);

        $data['modulo'] = $data['modulo'] ?? 'relojeria';
        if (! Sucursal::permiteModulo($data['sucursal_id'], $data['modulo'])) {
            throw ValidationException::withMessages(['modulo' => 'Esta sucursal no tiene habilitado el módulo de Joyería.']);
        }

        if ($request->hasFile('foto')) {
            $data['foto'] = $request->file('foto')->store('inventario', 'public');
        }

        // El código ya no lo escribe quien crea el producto: lo genera el sistema
        // a partir del id autoincremental, que la base asigna de forma atómica al
        // insertar -- así queda garantizado que nunca se repite, sin necesitar un
        // lock ni una tabla de contadores aparte. Por eso se completa en un
        // segundo paso: recién se conoce el id después de crear la fila.
        $item = Inventario::create($data + ['codigo' => '']);
        $item->update(['codigo' => 'INV-'.str_pad((string) $item->id, 5, '0', STR_PAD_LEFT)]);

        return response()->json($item, 201);
    }

    /** Edita un producto existente (mismas reglas que store). El código no se toca: se generó al crear y no cambia. */
    public function update(Request $request, Inventario $inventario)
    {
        $data = $request->validate([
            'sucursal_id' => ['required', 'integer', 'exists:sucursales,id'],
            'modulo' => ['nullable', 'in:relojeria,joyeria'],
            'descripcion' => ['required', 'string', 'max:255'],
            'cantidad' => ['nullable', 'integer', 'min:0'],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'costo' => ['nullable', 'numeric', 'min:0'],
            'foto' => ['nullable', 'image', 'max:4096'],
        ]);

        $data['modulo'] = $data['modulo'] ?? $inventario->modulo;
        if (! Sucursal::permiteModulo($data['sucursal_id'], $data['modulo'])) {
            throw ValidationException::withMessages(['modulo' => 'Esta sucursal no tiene habilitado el módulo de Joyería.']);
        }

        $fotoAnterior = $inventario->foto;

        if ($request->hasFile('foto')) {
            $data['foto'] = $request->file('foto')->store('inventario', 'public');
        }

        $inventario->update($data);

        // Se borra después de guardar (no antes), para no perder la foto
        // vieja si la validación o el update fallaran a mitad de camino.
        if ($request->hasFile('foto') && $fotoAnterior) {
            Storage::disk('public')->delete($fotoAnterior);
        }

        return response()->json($inventario);
    }

    /** Borrado suave: el producto pasa a la papelera, no desaparece de la base. */
    public function destroy(Inventario $inventario)
    {
        $inventario->delete();

        return response()->json(null, 204);
    }

    /** Descarga el catálogo visible (mismo alcance que index()) en CSV. */
    public function exportar(Request $request)
    {
        $query = Inventario::query()->where('modulo', $request->query('modulo', 'relojeria'));
        $user = $request->user();

        if ($user->tipo !== 'admin') {
            $query->where('sucursal_id', $user->sucursal_id);
        } elseif ($request->filled('sucursal_id')) {
            $query->where('sucursal_id', $request->query('sucursal_id'));
        }

        $items = $query->with('sucursal:id,nombre')->orderBy('descripcion')->get();
        $nombreArchivo = 'inventario_'.now()->format('Y-m-d').'.csv';

        return new StreamedResponse(function () use ($items) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: para que Excel detecte UTF-8 y muestre bien los acentos
            fputcsv($out, ['Código', 'Descripción', 'Cantidad', 'Precio', 'Costo', 'Sucursal']);

            foreach ($items as $item) {
                fputcsv($out, [
                    $item->codigo,
                    $item->descripcion,
                    $item->cantidad,
                    $item->precio,
                    $item->costo,
                    $item->sucursal->nombre ?? '',
                ]);
            }

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nombreArchivo}\"",
        ]);
    }

    /**
     * Importa productos desde un CSV a una sucursal puntual: si el código ya
     * existe ahí se actualiza (cantidad/precio/costo/descripción), si no se
     * crea. Columnas esperadas (por nombre, no por posición): Código,
     * Descripción, Cantidad, Precio, Costo (opcional).
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

        $iCodigo = $col('código', 'codigo');
        $iDesc = $col('descripción', 'descripcion');
        $iCantidad = $col('cantidad');
        $iPrecio = $col('precio');
        $iCosto = $col('costo');

        if ($iCodigo === null) {
            fclose($handle);
            throw ValidationException::withMessages(['archivo' => 'El CSV debe tener una columna "Código".']);
        }

        $creados = 0;
        $actualizados = 0;
        $errores = [];
        $fila = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $fila++;
            $codigo = trim($row[$iCodigo] ?? '');
            if ($codigo === '') {
                continue;
            }

            $item = Inventario::where('sucursal_id', $data['sucursal_id'])->where('codigo', $codigo)->first();
            $descripcion = $iDesc !== null ? trim((string) ($row[$iDesc] ?? '')) : '';
            if ($descripcion === '') {
                $descripcion = $item->descripcion ?? '';
            }
            if ($descripcion === '') {
                $errores[] = "Fila {$fila}: falta descripción para el código \"{$codigo}\".";

                continue;
            }

            $valores = [
                'sucursal_id' => $data['sucursal_id'],
                'modulo' => $modulo,
                'codigo' => $codigo,
                'descripcion' => $descripcion,
                'cantidad' => $iCantidad !== null && is_numeric($row[$iCantidad] ?? null) ? (int) $row[$iCantidad] : ($item->cantidad ?? 0),
                'precio' => $iPrecio !== null && is_numeric($row[$iPrecio] ?? null) ? (float) $row[$iPrecio] : ($item->precio ?? 0),
                'costo' => $iCosto !== null && is_numeric($row[$iCosto] ?? null) ? (float) $row[$iCosto] : ($item->costo ?? 0),
            ];

            if ($item) {
                $item->update($valores);
                $actualizados++;
            } else {
                Inventario::create($valores);
                $creados++;
            }
        }

        fclose($handle);

        return response()->json([
            'creados' => $creados,
            'actualizados' => $actualizados,
            'errores' => $errores,
        ]);
    }
}
