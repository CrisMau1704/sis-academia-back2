<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Carbon\Carbon;

class Horario extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Los atributos que son asignables en masa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'nombre',
        'dia_semana',
        'hora_inicio',
        'hora_fin',
        'duracion_minutos',
        'disciplina_id',
        'sucursal_id',
        'entrenador_id',
        'modalidad_id',
        'cupo_maximo',
        'cupo_actual',
        'estado',
        'color',
        'descripcion'
    ];

    /**
     * Los atributos que deben ser casteados.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'hora_inicio' => 'datetime:H:i',
        'hora_fin' => 'datetime:H:i',
        'duracion_minutos' => 'integer',
        'cupo_maximo' => 'integer',
        'cupo_actual' => 'integer',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'deleted_at' => 'datetime'
    ];

    /**
     * Los atributos con valores por defecto.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => 'activo',
        'cupo_maximo' => 15,
        'cupo_actual' => 0,
        'duracion_minutos' => 60,
        'color' => '#3B82F6'
    ];

    /**
     * RELACIONES
     */

    /**
     * Obtener la disciplina del horario.
     */
    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class);
    }

    /**
     * Obtener la sucursal del horario.
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * Obtener el entrenador del horario.
     */
    public function entrenador(): BelongsTo
    {
        return $this->belongsTo(Entrenador::class);
    }

    /**
     * Obtener la modalidad del horario.
     */
    public function modalidad(): BelongsTo
    {
        return $this->belongsTo(Modalidad::class);
    }

    /**
     * Obtener las inscripciones del horario.
     */
    public function inscripciones(): BelongsToMany
    {
        return $this->belongsToMany(Inscripcion::class, 'inscripcion_horarios')
                    ->using(InscripcionHorario::class)
                    ->withPivot([
                        'id',
                        'clases_asistidas',
                        'clases_totales',
                      
                        'permisos_usados',
                        'fecha_inicio',
                        'fecha_fin',
                        'estado'
                    ])
                    ->withTimestamps();
    }

    /**
     * Obtener los registros pivote de inscripcion_horarios.
     */
    public function inscripcionHorarios()
    {
        return $this->hasMany(InscripcionHorario::class);
    }

    /**
     * SCOPES
     */

    /**
     * Scope para horarios activos.
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }

    /**
     * Scope para horarios inactivos.
     */
    public function scopeInactivos($query)
    {
        return $query->where('estado', 'inactivo');
    }

    /**
     * Scope para horarios con cupo disponible.
     */
    public function scopeConCupo($query)
    {
        return $query->whereRaw('cupo_actual < cupo_maximo');
    }

    /**
     * Scope para horarios completos (sin cupo).
     */
    public function scopeCompletos($query)
    {
        return $query->where('estado', 'completo');
    }

    /**
     * Scope para horarios por día de la semana.
     */
    public function scopePorDia($query, $dia)
    {
        return $query->where('dia_semana', $dia);
    }

    /**
     * Scope para horarios por disciplina.
     */
    public function scopePorDisciplina($query, $disciplinaId)
    {
        return $query->where('disciplina_id', $disciplinaId);
    }

    /**
     * Scope para horarios por sucursal.
     */
    public function scopePorSucursal($query, $sucursalId)
    {
        return $query->where('sucursal_id', $sucursalId);
    }

    /**
     * Scope para horarios por entrenador.
     */
    public function scopePorEntrenador($query, $entrenadorId)
    {
        return $query->where('entrenador_id', $entrenadorId);
    }

    /**
     * Scope para horarios en un rango de horas.
     */
    public function scopeEnRangoHoras($query, $horaInicio, $horaFin)
    {
        return $query->where('hora_inicio', '>=', $horaInicio)
                     ->where('hora_fin', '<=', $horaFin);
    }

    /**
     * ACCESORES
     */

    /**
     * Obtener la hora formateada (ej: "18:00 - 19:30").
     */
    public function getHoraFormateadaAttribute(): string
    {
        return $this->hora_inicio->format('H:i') . ' - ' . $this->hora_fin->format('H:i');
    }

    /**
     * Obtener el horario completo (ej: "Lunes 18:00-19:30").
     */
    public function getHorarioCompletoAttribute(): string
    {
        return $this->dia_semana . ' ' . $this->hora_formateada;
    }

    /**
     * Obtener la disponibilidad (cupos libres).
     */
    public function getDisponibilidadAttribute(): int
    {
        return max(0, $this->cupo_maximo - $this->cupo_actual);
    }

    /**
     * Obtener el porcentaje de ocupación.
     */
    public function getPorcentajeOcupacionAttribute(): float
    {
        if ($this->cupo_maximo == 0) {
            return 0;
        }
        
        return round(($this->cupo_actual / $this->cupo_maximo) * 100, 2);
    }

    /**
     * Verificar si hay cupo disponible.
     */
    public function getTieneCupoAttribute(): bool
    {
        return $this->cupo_actual < $this->cupo_maximo;
    }

    /**
     * Obtener el nivel de disponibilidad (alta, media, baja).
     */
    public function getNivelDisponibilidadAttribute(): string
    {
        $porcentaje = $this->porcentaje_ocupacion;
        
        if ($porcentaje >= 90) {
            return 'completo';
        } elseif ($porcentaje >= 70) {
            return 'baja';
        } elseif ($porcentaje >= 40) {
            return 'media';
        } else {
            return 'alta';
        }
    }

    /**
     * Obtener las inscripciones activas.
     */
    public function getInscripcionesActivasAttribute()
    {
        return $this->inscripciones()->wherePivot('estado', 'activo')->get();
    }

    /**
     * Contar inscripciones activas.
     */
    public function getTotalInscripcionesActivasAttribute(): int
    {
        return $this->inscripciones()->wherePivot('estado', 'activo')->count();
    }

    /**
     * Obtener el color basado en la disciplina si no hay color asignado.
     */
    public function getColorCalculadoAttribute(): string
    {
        if ($this->color && $this->color !== '#3B82F6') {
            return $this->color;
        }
        
        // Asignar colores por disciplina
        $coloresPorDisciplina = [
            'MMA' => '#3B82F6',      // Azul
            'King Boxer' => '#EF4444', // Rojo
            'Karate' => '#10B981',   // Verde
            'Boxeo' => '#8B5CF6',    // Violeta
            'Taekwondo' => '#F59E0B', // Naranja
            'Kickboxing' => '#EC4899', // Rosa
        ];
        
        return $coloresPorDisciplina[$this->disciplina->nombre] ?? '#3B82F6';
    }

    /**
     * Obtener el nombre del horario con información adicional.
     */
    public function getNombreCompletoAttribute(): string
    {
        return "{$this->nombre} ({$this->dia_semana} {$this->hora_formateada})";
    }

    /**
     * MUTADORES
     */

    /**
     * Asegurar que el color tenga formato hexadecimal.
     */
    public function setColorAttribute($value)
    {
        if ($value && !preg_match('/^#/', $value)) {
            $value = '#' . $value;
        }
        $this->attributes['color'] = $value;
    }

    /**
     * Calcular duración automáticamente al establecer horas.
     */
    public function setHoraInicioAttribute($value)
    {
        $this->attributes['hora_inicio'] = $value;
        $this->calcularDuracion();
    }

    public function setHoraFinAttribute($value)
    {
        $this->attributes['hora_fin'] = $value;
        $this->calcularDuracion();
    }

    /**
     * MÉTODOS DE NEGOCIO
     */

    /**
     * Incrementar el cupo actual (cuando se inscribe alguien).
     */
    public function incrementarCupo(): bool
    {
        if ($this->cupo_actual >= $this->cupo_maximo) {
            return false;
        }
        
        $this->cupo_actual++;
        
        // Actualizar estado si se llena
        if ($this->cupo_actual >= $this->cupo_maximo) {
            $this->estado = 'completo';
        }
        
        return $this->save();
    }

    /**
     * Decrementar el cupo actual (cuando se cancela una inscripción).
     */
    public function decrementarCupo(): bool
    {
        if ($this->cupo_actual <= 0) {
            return false;
        }
        
        $this->cupo_actual--;
        
        // Actualizar estado si ya no está completo
        if ($this->estado === 'completo' && $this->cupo_actual < $this->cupo_maximo) {
            $this->estado = 'activo';
        }
        
        return $this->save();
    }

    /**
     * Cambiar el estado del horario.
     */
    public function cambiarEstado(string $estado): bool
    {
        $estadosValidos = ['activo', 'inactivo', 'completo'];
        
        if (!in_array($estado, $estadosValidos)) {
            return false;
        }
        
        $this->estado = $estado;
        return $this->save();
    }

    /**
     * Verificar si hay conflicto con otro horario.
     */
    public function tieneConflictoCon(Horario $otroHorario): bool
    {
        // Mismo día
        if ($this->dia_semana !== $otroHorario->dia_semana) {
            return false;
        }
        
        // Mismo entrenador
        if ($this->entrenador_id === $otroHorario->entrenador_id) {
            return $this->seSuperponeCon($otroHorario);
        }
        
        // Misma sucursal
        if ($this->sucursal_id === $otroHorario->sucursal_id) {
            return $this->seSuperponeCon($otroHorario);
        }
        
        return false;
    }

    /**
     * Verificar si se superpone con otro horario.
     */
    public function seSuperponeCon(Horario $otroHorario): bool
    {
        $thisInicio = Carbon::parse($this->hora_inicio);
        $thisFin = Carbon::parse($this->hora_fin);
        $otroInicio = Carbon::parse($otroHorario->hora_inicio);
        $otroFin = Carbon::parse($otroHorario->hora_fin);
        
        return $thisInicio < $otroFin && $thisFin > $otroInicio;
    }

    /**
     * Obtener horarios disponibles para inscripción.
     */
    public static function obtenerDisponiblesParaInscripcion($disciplinaId = null, $sucursalId = null)
    {
        $query = self::activos()->conCupo()->with(['disciplina', 'sucursal', 'entrenador']);
        
        if ($disciplinaId) {
            $query->where('disciplina_id', $disciplinaId);
        }
        
        if ($sucursalId) {
            $query->where('sucursal_id', $sucursalId);
        }
        
        return $query->orderBy('dia_semana')
                    ->orderBy('hora_inicio')
                    ->get();
    }

    /**
     * Método para calcular duración automáticamente.
     */
    private function calcularDuracion(): void
    {
        if ($this->hora_inicio && $this->hora_fin) {
            $inicio = Carbon::parse($this->hora_inicio);
            $fin = Carbon::parse($this->hora_fin);
            $this->attributes['duracion_minutos'] = $inicio->diffInMinutes($fin);
        }
    }

    /**
     * Hook del modelo para calcular duración antes de guardar.
     */
    protected static function boot()
    {
        parent::boot();
        
        static::saving(function ($horario) {
            if ($horario->hora_inicio && $horario->hora_fin) {
                $horario->calcularDuracion();
            }
        });
    }
}