<?php

namespace App\Http\Controllers;

use App\Models\Pago;
use App\Models\Inscripcion; // Si necesitas esta relación
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log; // Para depuración

class PagoController extends Controller
{
    public function index()
    { 
        // Si necesitas cargar relaciones, asegúrate de que existan en el modelo
        return Pago::with(['inscripcion', 'inscripcion.estudiante'])->get(); 
    }
    
    public function store(Request $request)
    {
        // Para depurar lo que recibes
        Log::info('Datos recibidos en PagoController@store:', $request->all());
        
        // Validación según lo que envía tu Vue
        $request->validate([
            'inscripcion_id' => 'required|exists:inscripciones,id',
            'monto' => 'required|numeric|min:0.01',
            'metodo_pago' => 'required|string|in:efectivo,qr,tarjeta,transferencia',
            'fecha_pago' => 'required|date', // Cambiado de 'fecha' a 'fecha_pago'
            'estado' => 'nullable|string',
            'observacion' => 'nullable|string|max:500'
        ]);
        
        try {
            // Crear el pago
            $pago = Pago::create([
                'inscripcion_id' => $request->inscripcion_id,
                'monto' => $request->monto,
                'metodo_pago' => $request->metodo_pago,
                'fecha_pago' => $request->fecha_pago, // Usar fecha_pago
                'estado' => $request->estado ?? 'pagado',
                'observacion' => $request->observacion
            ]);
            
            Log::info('Pago creado exitosamente:', $pago->toArray());
            
            return response()->json([
                'success' => true,
                'message' => 'Pago registrado exitosamente',
                'data' => $pago->load('inscripcion') // Cargar relación si es necesario
            ], 201);
            
        } catch (\Exception $e) {
            Log::error('Error al crear pago: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar el pago: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function show($id)
    { 
        $pago = Pago::with('inscripcion')->findOrFail($id);
        
        return response()->json([
            'success' => true,
            'data' => $pago
        ]);
    }
    
    public function update(Request $request, $id)
    {
        $pago = Pago::findOrFail($id);
        
        $request->validate([
            'monto' => 'numeric|min:0.01',
            'metodo_pago' => 'string|in:efectivo,qr,tarjeta,transferencia',
            'fecha_pago' => 'date',
            'estado' => 'string',
            'observacion' => 'nullable|string|max:500'
        ]);
        
        $pago->update($request->only([
            'monto', 'metodo_pago', 'fecha_pago', 'estado', 'observacion'
        ]));
        
        return response()->json([
            'success' => true,
            'message' => 'Pago actualizado exitosamente',
            'data' => $pago
        ]);
    }
    
    public function destroy($id)
    {
        $pago = Pago::findOrFail($id);
        $pago->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Pago eliminado exitosamente'
        ]);
    }
}