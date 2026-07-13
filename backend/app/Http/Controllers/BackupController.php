<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/** Respaldo de la base de datos (solo admin): un único endpoint que genera y descarga un .sql. */
class BackupController extends Controller
{
    /**
     * Genera un dump SQL completo (estructura + datos) en PHP puro, sin
     * depender de que el binario `mysqldump` esté disponible/en el PATH —
     * así funciona igual en este XAMPP local que en cualquier hosting real.
     */
    public function exportar()
    {
        $dbName = DB::getDatabaseName();
        $tablas = collect(DB::select('SHOW TABLES'))
            ->map(fn ($fila) => array_values((array) $fila)[0]);

        $sql = "-- Backup de \"{$dbName}\" generado el ".now()->toDateTimeString()."\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tablas as $tabla) {
            $create = DB::select("SHOW CREATE TABLE `{$tabla}`")[0]->{'Create Table'};
            $sql .= "DROP TABLE IF EXISTS `{$tabla}`;\n{$create};\n\n";

            // Tablas chicas (prototipo de una relojería, no millones de filas):
            // un get() simple alcanza, sin depender de una columna de orden
            // que no todas las tablas tienen (ej. "cache" usa "key").
            foreach (DB::table($tabla)->get() as $fila) {
                $fila = (array) $fila;
                $columnas = implode('`, `', array_keys($fila));
                $valores = implode(', ', array_map(function ($valor) {
                    if (is_null($valor)) {
                        return 'NULL';
                    }

                    return "'".str_replace(["\\", "'", "\n", "\r"], ['\\\\', "\\'", '\\n', '\\r'], (string) $valor)."'";
                }, $fila));
                $sql .= "INSERT INTO `{$tabla}` (`{$columnas}`) VALUES ({$valores});\n";
            }

            $sql .= "\n";
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $nombreArchivo = "backup_{$dbName}_".now()->format('Y-m-d_His').'.sql';

        return response($sql, 200, [
            'Content-Type' => 'application/sql; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nombreArchivo}\"",
        ]);
    }
}
