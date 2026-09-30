<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\ActividadController;
use App\Http\Controllers\CierreDiarioController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\MovimientoController;
use App\Http\Controllers\PapeleraController;
use App\Http\Controllers\ReparacionController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\SolicitudPasswordController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VentaController;
use Illuminate\Support\Facades\Route;

// throttle:6,1 -> máximo 6 intentos por minuto por IP, para frenar fuerza bruta de contraseñas.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
// throttle:10,1 -> igual que /login, para no permitir enumerar usuarios/emails
// existentes (la validación "unique" delata cuáles ya están tomados) ni crear
// cuentas en masa.
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
// Pública: se necesita para el selector de sucursal en la pantalla de registro, antes de tener sesión.
Route::get('/sucursales', [SucursalController::class, 'index']);
// Pública: "olvidé mi contraseña" se pide sin sesión. Responde igual exista o no el usuario.
Route::post('/olvide-password', [SolicitudPasswordController::class, 'store'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);

    // Disponible para cualquier usuario autenticado (admin o empleado).
    Route::get('/inventario', [InventarioController::class, 'index']);
    // Ruta literal antes que cualquier futura /inventario/{id}, para que no choquen.
    Route::get('/inventario/disponibilidad', [InventarioController::class, 'disponibilidad']);
    Route::get('/ventas', [VentaController::class, 'index']);
    Route::post('/ventas', [VentaController::class, 'store']);
    // El empleado puede corregir/borrar una venta de su propia sucursal (para
    // poder cuadrar caja si se equivocó); el controller valida esa sucursal.
    Route::patch('/ventas/{venta}', [VentaController::class, 'update']);
    Route::delete('/ventas/{venta}', [VentaController::class, 'destroy']);
    Route::get('/reparaciones', [ReparacionController::class, 'index']);
    Route::post('/reparaciones', [ReparacionController::class, 'store']);
    // El empleado solo puede mover el estado; el controller restringe el resto de campos a admins.
    Route::put('/reparaciones/{reparacion}', [ReparacionController::class, 'update']);

    Route::get('/movimientos', [MovimientoController::class, 'index']);
    Route::post('/movimientos', [MovimientoController::class, 'store']);

    // A diferencia del resto de rutas de /cierres (exportar/detalle, admin), estas
    // dos las necesita también el empleado: "hoy" para cuadrar caja antes de que el
    // dueño pase a recoger el efectivo, e "index" para el gráfico de ventas del
    // dashboard (ya viene escopeado a su propia sucursal, ver aplicarFiltros()).
    // Ruta literal antes de la comodín {fecha} (declarada más abajo, admin-only),
    // para que "hoy" no choque con ella.
    Route::get('/cierres/hoy', [CierreDiarioController::class, 'hoy']);
    Route::get('/cierres', [CierreDiarioController::class, 'index']);

    // Solo admin (propietario).
    Route::middleware('admin')->group(function () {
        Route::delete('/movimientos/{movimiento}', [MovimientoController::class, 'destroy']);

        Route::get('/actividad', [ActividadController::class, 'index']);

        Route::post('/inventario', [InventarioController::class, 'store']);
        Route::put('/inventario/{inventario}', [InventarioController::class, 'update']);
        Route::delete('/inventario/{inventario}', [InventarioController::class, 'destroy']);
        Route::get('/inventario/exportar', [InventarioController::class, 'exportar']);
        Route::post('/inventario/importar', [InventarioController::class, 'importar']);

        Route::get('/ventas/exportar', [VentaController::class, 'exportar']);
        Route::post('/ventas/importar', [VentaController::class, 'importar']);

        Route::delete('/reparaciones/{reparacion}', [ReparacionController::class, 'destroy']);

        Route::get('/reportes', [ReporteController::class, 'index']);

        Route::post('/sucursales', [SucursalController::class, 'store']);
        Route::put('/sucursales/{sucursal}', [SucursalController::class, 'update']);

        Route::get('/usuarios', [UserController::class, 'index']);
        Route::post('/usuarios', [UserController::class, 'store']);
        Route::put('/usuarios/{user}', [UserController::class, 'update']);
        Route::post('/usuarios/{user}/aprobar', [UserController::class, 'aprobar']);

        // Ruta literal antes de la comodín {fecha} para que no choque.
        Route::get('/cierres/exportar', [CierreDiarioController::class, 'exportar']);
        Route::get('/cierres/{fecha}', [CierreDiarioController::class, 'detalle'])->where('fecha', '\d{4}-\d{2}-\d{2}');

        Route::get('/papelera', [PapeleraController::class, 'index']);
        Route::post('/papelera/{tipo}/{id}/restaurar', [PapeleraController::class, 'restaurar']);

        Route::get('/backup/exportar', [BackupController::class, 'exportar']);

        Route::get('/solicitudes-password', [SolicitudPasswordController::class, 'index']);
        Route::post('/solicitudes-password/{solicitud}/atender', [SolicitudPasswordController::class, 'atender']);
    });
});
