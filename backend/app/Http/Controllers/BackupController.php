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
     * Soporta MySQL y SQLite (el driver por defecto de este proyecto) porque
     * cada uno expone el esquema de forma distinta (SHOW CREATE TABLE vs.
     * sqlite_master).
     */
    public function exportar()
    {
        $driver = DB::connection()->getDriverName();
        $dbName = $driver === 'sqlite' ? 'database' : DB::getDatabaseName();

        $sql = "-- Backup de \"{$dbName}\" generado el ".now()->toDateTimeString()."\n\n";
        $sql .= $driver === 'sqlite' ? "PRAGMA foreign_keys=OFF;\n\n" : "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($this->tablasYCreates($driver) as $tabla => $create) {
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

        $sql .= $driver === 'sqlite' ? "PRAGMA foreign_keys=ON;\n" : "SET FOREIGN_KEY_CHECKS=1;\n";

        $nombreArchivo = "backup_{$dbName}_".now()->format('Y-m-d_His').'.sql';

        return response($sql, 200, [
            'Content-Type' => 'application/sql; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nombreArchivo}\"",
        ]);
    }

    /** Mapa "nombre de tabla" -> sentencia CREATE TABLE, según el driver activo. */
    private function tablasYCreates(string $driver): array
    {
        if ($driver === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('type', 'table')
                ->whereNotNull('sql')
                ->where('name', 'not like', 'sqlite_%')
                ->pluck('sql', 'name')
                ->all();
        }

        $tablas = collect(DB::select('SHOW TABLES'))
            ->map(fn ($fila) => array_values((array) $fila)[0]);

        $creates = [];
        foreach ($tablas as $tabla) {
            $creates[$tabla] = DB::select("SHOW CREATE TABLE `{$tabla}`")[0]->{'Create Table'};
        }

        return $creates;
    }
}
