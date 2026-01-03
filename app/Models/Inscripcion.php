<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Inscripcion extends Model
{
    use HasFactory;
    
    protected $table = 'inscripciones';
    
    protected $fillable = [
        'estudiante_id',
        'modalidad_id',
        'sucursal_id',
        'entrenador_id',
        'fecha_inicio',
        'fecha_fin',
        'clases_totales',
        'clases_asistidas', // ← Este SÍ existe
        'permisos_usados',
        'monto_mensual',
        'estado'
        // ¡NO incluir 'clases_restantes' aquí!
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'clases_totales' => 'integer',
        'clases_asistidas' => 'integer', // ← Cambia esto
        'permisos_usados' => 'integer',
        'monto_mensual' => 'decimal:2'
        // ¡NO incluir 'clases_restantes' aquí!
    ];

    protected $attributes = [
        'estado' => 'activa', // ← Asegúrate que sea 'activa' no 'activo'
        'clases_totales' => 12,
        'clases_asistidas' => 0, // ← Cambia esto
        'permisos_usados' => 0
        // ¡NO incluir 'clases_restantes' aquí!
    ];

    // Relaciones
    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class);
    }

    public function modalidad(): BelongsTo
    {
        return $this->belongsTo(Modalidad::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function entrenador(): BelongsTo
    {
        return $this->belongsTo(Entrenador::class, 'entrenador_id');
    }

    public function horarios(): BelongsToMany
    {
        return $this->belongsToMany(Horario::class, 'inscripcion_horarios')
                    ->using(InscripcionHorario::class)
                    ->withPivot([
                        'id',
                        'clases_asistidas',
                        'clases_totales',
                        'clases_restantes', // ← ¡Este SÍ está bien aquí! Es de la tabla pivote
                        'permisos_usados',
                        'fecha_inicio',
                        'fecha_fin',
                        'estado'
                    ])
                    ->withTimestamps();
    }

    public function inscripcionHorarios()
    {
        return $this->hasMany(InscripcionHorario::class, 'inscripcion_id');
    }

    // Scopes
    public function scopeActivas($query)
    {
        return $query->where('estado', 'activa');
    }

    public function scopePorVencer($query, $dias = 7)
    {
        return $query->where('estado', 'activa')
                    ->where('fecha_fin', '<=', now()->addDays($dias))
                    ->where('fecha_fin', '>=', now());
    }

    public function scopeVencidas($query)
    {
        return $query->where('estado', 'activa')
                    ->where('fecha_fin', '<', now());
    }

    // Métodos de ayuda
    public function calcularDiasRestantes()
    {
        if (!$this->fecha_fin) return 0;
        
        $hoy = now();
        $fin = $this->fecha_fin;
        
        return $hoy->diffInDays($fin, false); // negativo si ya pasó
    }
    
    public function getDiasRestantesAttribute()
    {
        return $this->calcularDiasRestantes();
    }
    
    // Método para calcular clases restantes totales
    public function getClasesRestantesTotalesAttribute()
    {
        // Sumar clases_restantes de todos los inscripcion_horarios
        return $this->inscripcionHorarios()->sum('clases_restantes');
    }
}