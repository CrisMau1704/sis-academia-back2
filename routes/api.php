<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EstudianteController;
use App\Http\Controllers\ModalidadController;
use App\Http\Controllers\HorarioController;
use App\Http\Controllers\InscripcionController;
use App\Http\Controllers\AsistenciaController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\EntrenadorController;
use App\Http\Controllers\DisciplinaController;

use App\Http\Controllers\PagoController; // <-- AÑADE ESTA LÍNEA

use App\Http\Controllers\UserRoleController;
use App\Http\Controllers\UserController;

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/
Route::prefix('v1/auth')->group(function () {

    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('profile', [AuthController::class, 'profile']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

/*
|--------------------------------------------------------------------------
| RUTAS PROTEGIDAS
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

        // Rutas para el CRUD de usuarios
    Route::get('/usuario', [UserController::class, 'index']);

    Route::post("/usuario", [UserController::class, "funGuardar"]);
    Route::get("/usuario/{id}", [UserController::class, "funMostrar"]);
    Route::put("/usuario/{id}", [UserController::class, "funModificar"]);
    Route::delete("/usuario/{id}", [UserController::class, "funEliminar"]);


    Route::apiResource('estudiantes', EstudianteController::class);

    Route::apiResource('sucursales', SucursalController::class);

     Route::apiResource('entrenadores', EntrenadorController::class);

    Route::apiResource('modalidades', ModalidadController::class);

    Route::apiResource('disciplinas', DisciplinaController::class);

    // Rutas para horarios
Route::prefix('horarios')->group(function () {
    Route::get('/', [HorarioController::class, 'index']);
    Route::post('/', [HorarioController::class, 'store']);
    Route::get('/disponibles', [HorarioController::class, 'horariosDisponibles']);
    Route::get('/por-dia', [HorarioController::class, 'horariosPorDia']);
    Route::get('/estadisticas', [HorarioController::class, 'estadisticas']);
    Route::get('/{id}', [HorarioController::class, 'show']);
    Route::put('/{id}', [HorarioController::class, 'update']);
    Route::delete('/{id}', [HorarioController::class, 'destroy']);
    Route::put('/{id}/estado', [HorarioController::class, 'cambiarEstado']);
    Route::post('/{id}/incrementar-cupo', [HorarioController::class, 'incrementarCupo']);
    Route::post('/{id}/decrementar-cupo', [HorarioController::class, 'decrementarCupo']);
    
    // NUEVA RUTA PARA HORARIOS POR MODALIDAD - Agrega esta línea
    Route::get('/modalidad/{modalidadId}', [HorarioController::class, 'porModalidad']);
});

// routes/api.php

Route::prefix('inscripciones')->group(function () {
    Route::get('/', [InscripcionController::class, 'index']);
    Route::get('/todas', [InscripcionController::class, 'obtenerTodos']); // <-- AGREGAR ESTA LÍNEA
    Route::post('/', [InscripcionController::class, 'store']);
    Route::get('/{id}', [InscripcionController::class, 'show']);
    Route::put('/{id}', [InscripcionController::class, 'update']);
    Route::delete('/{id}', [InscripcionController::class, 'destroy']);
    
    // Rutas adicionales
    Route::post('/{id}/horarios', [InscripcionController::class, 'asociarHorario']);
    Route::delete('/{inscripcionId}/horarios/{horarioId}', [InscripcionController::class, 'desasociarHorario']);
    Route::post('/{id}/renovar', [InscripcionController::class, 'renovar']);
    Route::post('/verificar-vencimientos', [InscripcionController::class, 'verificarVencimientos']);
});


Route::prefix('pagos')->group(function () {
    Route::get('/', [PagoController::class, 'index']);
    Route::post('/', [PagoController::class, 'store']);
    Route::get('/{id}', [PagoController::class, 'show']);
    Route::put('/{id}', [PagoController::class, 'update']);
    Route::delete('/{id}', [PagoController::class, 'destroy']);
    Route::get('/inscripcion/{inscripcion_id}', [PagoController::class, 'porInscripcion']);
    Route::put('/{id}/anular', [PagoController::class, 'anular']);
    Route::put('/{id}/confirmar', [PagoController::class, 'confirmar']);
});


        Route::get('/users-with-roles', [UserRoleController::class, 'index']);
    Route::get('/roles', [UserRoleController::class, 'getRoles']);
    Route::post('/assign-roles', [UserRoleController::class, 'assignRoles']);
});

/*
|--------------------------------------------------------------------------
| NO AUTORIZADO
|--------------------------------------------------------------------------
*/
Route::get('/no-autorizado', function () {
    return response()->json([
        'message' => 'No estás autorizado para ver este recurso'
    ], 403);
})->name('login');
