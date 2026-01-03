<?php

namespace App\Http\Controllers;

use App\Models\Inscripcion;
use App\Models\Estudiante;
use App\Models\Modalidad;
use App\Models\Horario;
use App\Models\InscripcionHorario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class InscripcionController extends Controller
{
   public function index(Request $request)
{
    try {
        $query = Inscripcion::with([
            'estudiante', 
            'modalidad', 
            'sucursal',
            'entrenador', 
            'horarios.disciplina', 
            'horarios.entrenador',
            'inscripcionHorarios'
        ])->latest();
        
        // Filtros...
        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }
        
        // SIEMPRE devolver todas sin paginación (más simple para Vue)
        $inscripciones = $query->get();
        
        // Calcular campos dinámicos
        foreach ($inscripciones as $inscripcion) {
            $inscripcion->clases_restantes_calculadas = $this->calcularClasesRestantes($inscripcion);
            $inscripcion->dias_restantes = $this->calcularDiasRestantes($inscripcion->fecha_fin);
        }
        
        return response()->json([
            'success' => true,
            'data' => $inscripciones  // ← Array directo, no paginado
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener inscripciones: ' . $e->getMessage()
        ], 500);
    }
}

    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $request->validate([
                'estudiante_id' => 'required|exists:estudiantes,id',
                'modalidad_id' => 'required|exists:modalidades,id',
                'sucursal_id' => 'required|exists:sucursales,id',
                'entrenador_id' => 'required|exists:entrenadores,id',
                'fecha_inicio' => 'required|date',
                'monto_mensual' => 'required|numeric|min:0',
                'horarios' => 'sometimes|array',
                'horarios.*' => 'exists:horarios,id',
            ]);
            
            // Obtener modalidad
            $modalidad = Modalidad::findOrFail($request->modalidad_id);
            
            // Calcular fecha de fin
            $fechaInicio = Carbon::parse($request->fecha_inicio);
            $fechaFin = $request->has('fecha_fin') 
                ? Carbon::parse($request->fecha_fin)
                : $fechaInicio->copy()->addMonth();
            
            // ========== PASO 1: Crear inscripción principal ==========
            // ¡SOLO con los campos que EXISTEN en la tabla `inscripciones`!
            $inscripcion = Inscripcion::create([
                'estudiante_id' => $request->estudiante_id,
                'modalidad_id' => $request->modalidad_id,
                'sucursal_id' => $request->sucursal_id,
                'entrenador_id' => $request->entrenador_id,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'clases_totales' => $modalidad->clases_mensuales ?? 12,
                'clases_asistidas' => 0,  // ← Inicializar en 0
                'permisos_usados' => 0,   // ← Inicializar en 0
                'monto_mensual' => $request->monto_mensual,
                'estado' => 'activa'
                // ¡NO incluir 'clases_restantes' aquí! No existe en `inscripciones`
            ]);
            
            // ========== PASO 2: Asociar horarios ==========
            if ($request->has('horarios') && is_array($request->horarios) && count($request->horarios) > 0) {
                $this->asociarHorarios($inscripcion, $request->horarios, $modalidad);
            }
            
            DB::commit();
            
            // Cargar relaciones para respuesta
            $inscripcion->load([
                'estudiante', 
                'modalidad', 
                'sucursal', 
                'entrenador', 
                'horarios',
                'inscripcionHorarios'
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Inscripción creada exitosamente',
                'data' => $inscripcion
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la inscripción: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $inscripcion = Inscripcion::with([
            'estudiante', 
            'modalidad', 
            'sucursal',
            'entrenador',
            'horarios.disciplina', 
            'horarios.entrenador',
            'horarios.sucursal',
            'inscripcionHorarios' => function($query) {
                $query->select('id', 'inscripcion_id', 'horario_id', 
                              'clases_totales', 'clases_asistidas', 
                              'clases_restantes', 'permisos_usados', 'estado'); // ← 'clases_restantes'
            }
        ])->findOrFail($id);
        
        // Calcular estadísticas
        $totalClasesAsistidas = $inscripcion->inscripcionHorarios->sum('clases_asistidas');
        $totalClasesRestantes = $inscripcion->inscripcionHorarios->sum('clases_restantes'); // ← CORREGIDO
        $totalPermisosUsados = $inscripcion->inscripcionHorarios->sum('permisos_usados');
        
        $inscripcion->estadisticas = [
            'clases_asistidas' => $totalClasesAsistidas,
            'clases_restantes' => $totalClasesRestantes, // ← CORREGIDO
            'permisos_usados' => $totalPermisosUsados,
            'porcentaje_asistencia' => $inscripcion->clases_totales > 0 
                ? round(($totalClasesAsistidas / $inscripcion->clases_totales) * 100, 2)
                : 0
        ];
        
        return response()->json([
            'success' => true,
            'data' => $inscripcion
        ]);
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $inscripcion = Inscripcion::findOrFail($id);
            
            $request->validate([
                'estado' => 'sometimes|in:activa,suspendida,en_mora,vencida', // ← según tus enum
                'fecha_fin' => 'sometimes|date',
                'clases_asistidas' => 'sometimes|integer|min:0', // ← este sí existe
                'permisos_usados' => 'sometimes|integer|min:0',
                'horarios' => 'sometimes|array',
                'horarios.*' => 'exists:horarios,id'
            ]);
            
            // Actualizar solo campos que existen en `inscripciones`
            $camposPermitidos = [
                'estado', 'fecha_fin', 'clases_asistidas', 'permisos_usados'
            ];
            
            $datosActualizar = $request->only($camposPermitidos);
            $inscripcion->update($datosActualizar);
            
            // Si se envían horarios, actualizarlos
            if ($request->has('horarios')) {
                $this->actualizarHorarios($inscripcion, $request->horarios);
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Inscripción actualizada exitosamente',
                'data' => $inscripcion->load(['estudiante', 'modalidad', 'horarios'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la inscripción: ' . $e->getMessage()
            ], 500);
        }
    }

    public function renovar($id, Request $request)
    {
        try {
            $inscripcion = Inscripcion::findOrFail($id);
            
            $request->validate([
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin' => 'sometimes|date|after:fecha_inicio',
                'motivo' => 'nullable|string'
            ]);
            
            // Actualizar fechas
            $fechaInicio = $request->has('fecha_inicio') 
                ? Carbon::parse($request->fecha_inicio)
                : now();
                
            $fechaFin = $request->has('fecha_fin')
                ? Carbon::parse($request->fecha_fin)
                : $fechaInicio->copy()->addMonth();
            
            // Actualizar inscripción principal
            $inscripcion->update([
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'clases_asistidas' => 0, // ← Reiniciar contadores
                'permisos_usados' => 0,
                'estado' => 'activa'
            ]);
            
            // Actualizar también los inscripcion_horarios
            $inscripcion->inscripcionHorarios()->update([
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'clases_asistidas' => 0,
                'clases_restantes' => DB::raw('clases_totales'), // ← Reiniciar clases restantes
                'permisos_usados' => 0,
                'estado' => 'activo'
            ]);
            
            // Cargar relaciones
            $inscripcion->load(['estudiante', 'modalidad']);
            
            return response()->json([
                'success' => true,
                'message' => 'Inscripción renovada exitosamente',
                'data' => $inscripcion
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al renovar inscripción: ' . $e->getMessage()
            ], 500);
        }
    }

    // ========== MÉTODOS PRIVADOS CORREGIDOS ==========

    private function asociarHorarios($inscripcion, $horariosIds, $modalidad)
    {
        $totalHorarios = count($horariosIds);
        if ($totalHorarios === 0) return;
        
        // Distribuir clases equitativamente entre horarios
        $clasesPorHorario = floor(($modalidad->clases_mensuales ?? 12) / $totalHorarios);
        
        foreach ($horariosIds as $horarioId) {
            $horario = Horario::findOrFail($horarioId);
            
            // Verificar cupo
            if ($horario->cupo_actual >= $horario->cupo_maximo) {
                throw new \Exception("El horario {$horario->nombre} no tiene cupo disponible");
            }
            
            // ========== Crear inscripcion_horario ==========
            InscripcionHorario::create([
                'inscripcion_id' => $inscripcion->id,
                'horario_id' => $horarioId,
                'clases_totales' => $clasesPorHorario,
                'clases_asistidas' => 0,
                'clases_restantes' => $clasesPorHorario, // ← ¡AQUÍ SÍ VA clases_restantes!
                'permisos_usados' => 0,
                'fecha_inicio' => $inscripcion->fecha_inicio,
                'fecha_fin' => $inscripcion->fecha_fin,
                'estado' => 'activo'
            ]);
            
            // Incrementar cupo del horario
            $horario->increment('cupo_actual');
        }
    }

    private function actualizarHorarios($inscripcion, $horariosIds)
    {
        // Obtener horarios actuales
        $horariosActuales = $inscripcion->horarios()->pluck('horarios.id')->toArray();
        
        // Horarios a eliminar
        $horariosAEliminar = array_diff($horariosActuales, $horariosIds);
        
        // Horarios a agregar
        $horariosAAgregar = array_diff($horariosIds, $horariosActuales);
        
        // Eliminar horarios
        foreach ($horariosAEliminar as $horarioId) {
            $this->desasociarHorario($inscripcion->id, $horarioId);
        }
        
        // Agregar nuevos horarios
        if (count($horariosAAgregar) > 0) {
            $modalidad = $inscripcion->modalidad;
            $this->asociarHorarios($inscripcion, $horariosAAgregar, $modalidad);
        }
    }

    private function recalcularDistribucionClases($inscripcion)
    {
        $totalHorarios = $inscripcion->horarios()->count();
        
        if ($totalHorarios === 0) return;
        
        // Calcular nuevas clases por horario
        $clasesPorHorario = floor($inscripcion->clases_totales / $totalHorarios);
        
        foreach ($inscripcion->inscripcionHorarios as $inscripcionHorario) {
            // Mantener las clases asistidas, ajustar el resto
            $clasesAsistidas = $inscripcionHorario->clases_asistidas;
            $nuevasClasesTotales = $clasesPorHorario;
            $nuevasClasesRestantes = max(0, $nuevasClasesTotales - $clasesAsistidas);
            
            $inscripcionHorario->update([
                'clases_totales' => $nuevasClasesTotales,
                'clases_restantes' => $nuevasClasesRestantes // ← CORREGIDO
            ]);
        }
    }

    // Métodos auxiliares nuevos
    private function calcularClasesRestantes($inscripcion)
    {
        // Sumar clases restantes de todos los horarios
        return $inscripcion->inscripcionHorarios->sum('clases_restantes');
    }
    
    private function calcularDiasRestantes($fechaFin)
    {
        if (!$fechaFin) return 0;
        
        $hoy = Carbon::now();
        $fin = Carbon::parse($fechaFin);
        
        return $hoy->diffInDays($fin, false); // negativo si ya pasó
    }

    // Método para registrar asistencia (agregar al controlador)
    public function registrarAsistencia($inscripcionId, $horarioId)
    {
        DB::beginTransaction();
        
        try {
            $inscripcionHorario = InscripcionHorario::where('inscripcion_id', $inscripcionId)
                ->where('horario_id', $horarioId)
                ->firstOrFail();
            
            // Verificar si hay clases disponibles
            if ($inscripcionHorario->clases_restantes <= 0) {
                throw new \Exception('No hay clases disponibles en este horario');
            }
            
            // Actualizar inscripcion_horario
            $inscripcionHorario->increment('clases_asistidas');
            $inscripcionHorario->decrement('clases_restantes');
            
            // Actualizar inscripción principal (sumar totales)
            $inscripcion = $inscripcionHorario->inscripcion;
            $inscripcion->increment('clases_asistidas');
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Asistencia registrada exitosamente'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar asistencia: ' . $e->getMessage()
            ], 500);
        }
    }
}