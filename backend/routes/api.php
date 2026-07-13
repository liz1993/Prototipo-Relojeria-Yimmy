<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CierreDiarioController;
use App\Http\Controllers\InventarioController;
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
Route::post('/register', [AuthController::class, 'register']);
// Pública: se necesita para el selector de sucursal en la pantalla de registro, antes de tener sesión.
Route::get('/sucursales', [SucursalController::class, 'index']);
// Pública: "olvidé mi contraseña" se pide sin sesión. Responde igual exista o no el usuario.
Route::post('/olvide-password', [SolicitudPasswordController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);

    // Disponible para cualquier usuario autenticado (admin o empleado).
    Route::get('/inventario', [InventarioController::class, 'index']);
    Route::get('/ventas', [VentaController::class, 'index']);
    Route::post('/ventas', [VentaController::class, 'store']);
    Route::get('/reparaciones', [ReparacionController::class, 'index']);
    Route::post('/reparaciones', [ReparacionController::class, 'store']);
    // El empleado solo puede mover el estado; el controller restringe el resto de campos a admins.
    Route::put('/reparaciones/{reparacion}', [ReparacionController::class, 'update']);

    // Solo admin (propietario).
    Route::middleware('admin')->group(function () {
        Route::post('/inventario', [InventarioController::class, 'store']);
        Route::put('/inventario/{inventario}', [InventarioController::class, 'update']);
        Route::delete('/inventario/{inventario}', [InventarioController::class, 'destroy']);

        Route::patch('/ventas/{venta}', [VentaController::class, 'update']);
        Route::delete('/ventas/{venta}', [VentaController::class, 'destroy']);

        Route::delete('/reparaciones/{reparacion}', [ReparacionController::class, 'destroy']);

        Route::get('/reportes', [ReporteController::class, 'index']);

        Route::post('/sucursales', [SucursalController::class, 'store']);
        Route::put('/sucursales/{sucursal}', [SucursalController::class, 'update']);

        Route::get('/usuarios', [UserController::class, 'index']);
        Route::post('/usuarios', [UserController::class, 'store']);
        Route::put('/usuarios/{user}', [UserController::class, 'update']);

        // Rutas literales antes de la comodín {fecha} para que no choquen.
        Route::get('/cierres/exportar', [CierreDiarioController::class, 'exportar']);
        Route::get('/cierres', [CierreDiarioController::class, 'index']);
        Route::get('/cierres/{fecha}', [CierreDiarioController::class, 'detalle'])->where('fecha', '\d{4}-\d{2}-\d{2}');

        Route::get('/papelera', [PapeleraController::class, 'index']);
        Route::post('/papelera/{tipo}/{id}/restaurar', [PapeleraController::class, 'restaurar']);

        Route::get('/backup/exportar', [BackupController::class, 'exportar']);

        Route::get('/solicitudes-password', [SolicitudPasswordController::class, 'index']);
        Route::post('/solicitudes-password/{solicitud}/atender', [SolicitudPasswordController::class, 'atender']);
    });
});
