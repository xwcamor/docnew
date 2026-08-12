<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suelta el candado con el que nacio todo el catalogo migrado.
 *
 * Al traer la v1 se bloqueaban de entrada tipos de trabajo, sedes, puestos,
 * areas, cargos y reglas de aprobacion. La idea era una pausa antes de
 * renombrar algo que citan miles de planes firmados. En la practica el candado
 * era de nivel 'super', y `Lockable::canBeUnlockedBy()` solo deja a un admin
 * quitar los de nivel 'tenant': el cliente que acababa de migrar su sistema se
 * encontraba **su propio catalogo intocable**, con el mensaje de que lo habia
 * bloqueado un administrador del sistema y sin ninguna forma de arreglarlo
 * desde la aplicacion.
 *
 * Ya se quito de `docufiz:migrate-data` y de la migracion que creo las
 * columnas, asi que una instalacion nueva no vuelve a caer. Esto es para las
 * bases que ya lo tienen puesto.
 *
 * Se sueltan **solo** los candados que puso el sistema: nivel 'super' sobre una
 * fila con `legacy_id`. Un candado que un administrador puso a mano despues
 * —sobre lo migrado o sobre lo que dio de alta el— es una decision de una
 * persona y no se toca.
 */
return new class extends Migration
{
    private array $tablas = [
        'work_types', 'work_locations', 'workstations',
        'work_areas', 'positions', 'nationalities', 'approval_rules',
    ];

    public function up(): void
    {
        foreach ($this->tablas as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'locked_at')) {
                continue;
            }

            if (! Schema::hasColumn($tabla, 'legacy_id')) {
                continue;
            }

            DB::table($tabla)
                ->whereNotNull('legacy_id')
                ->whereNotNull('locked_at')
                ->where('lock_scope', 'super')
                ->update(['locked_at' => null, 'locked_by' => null, 'lock_scope' => null]);
        }
    }

    /**
     * No se vuelve a bloquear.
     *
     * Deshacer esto seria devolver al cliente un catalogo que no puede editar,
     * que es el fallo que esta migracion arregla. Y no hay como distinguir lo
     * que solto esta migracion de lo que ya estaba suelto, asi que revertir
     * bloquearia tambien lo que nunca estuvo bloqueado.
     */
    public function down(): void
    {
        // A proposito, nada.
    }
};
