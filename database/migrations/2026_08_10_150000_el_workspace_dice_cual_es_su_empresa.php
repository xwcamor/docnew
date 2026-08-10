<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cual de las empresas del catalogo es el propio workspace.
 *
 * Es el dato que se perdio al portar la v1. Alli habia tres tablas de personas
 * y la diferencia estaba en el esquema, sin que nadie tuviera que declararla:
 *
 *   workers          → company_id NOT NULL, position_id NOT NULL
 *   supervisors      → sin company_id
 *   hse_supervisors  → sin company_id
 *
 * O sea: el trabajador pertenece a una contratista; el supervisor y el de
 * seguridad no pertenecen a ninguna, porque son del que contrata. Al fusionar
 * las tres en un unico `people` con vinculos a empresa, esa distincion se
 * evaporo y el sistema dejo de poder responder «¿este es de los mios o de la
 * contratista?».
 *
 * Sin la respuesta pasan tres cosas, y las tres estan pasando:
 *
 *   · El selector de aprobadores ofrece las 231 personas del padron, asi que
 *     se puede designar al ayudante de una contratista como supervisor
 *     autorizante del que contrata. Solo hace falta que alguien le marcara el
 *     rol.
 *   · La ficha pregunta «¿que aprueba?» a los 372 trabajadores de contratistas,
 *     que no van a aprobar nada nunca.
 *   · No hay forma de separar «mi gente» de «gente de contratistas» en ningun
 *     informe: son todas filas iguales.
 *
 * No se adivina cual es. El nombre del workspace no tiene por que coincidir con
 * el de la empresa, y los planes apuntan a la contratista que ejecuta, no a
 * quien contrata. Lo dice el admin en Ajustes del workspace, una vez.
 *
 * `nullOnDelete` y no `restrict`: si alguien borra la empresa, el workspace se
 * queda sin marcar —y la pantalla se lo dira— pero no se le bloquea el borrado
 * con un error que no sabria interpretar.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('name')
                ->comment('Cual de las empresas del catalogo es este workspace');
        });

        // La clave ajena, solo donde el motor la admite sin rehacer la tabla.
        //
        // SQLite —el de los tests— no sabe añadir una FK con ALTER: Laravel
        // recrea la tabla entera y vuelve a crear sus indices. `tenants` tiene
        // un indice unico PARCIAL escrito con SQL a mano (`... WHERE deleted_at
        // IS NULL`), que la reconstruccion no sabe leer y emite vacio:
        // «create unique index "tenants_name_unique_active" on "tenants" ()».
        // Reventaban los 558 tests a la vez.
        //
        // La integridad la sostiene la validacion del formulario, que es donde
        // entra este dato. En Postgres —produccion— la FK si se pone.

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('tenants', function (Blueprint $table) {
                $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['company_id']);
            }
            $table->dropColumn('company_id');
        });
    }
};
