<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las fechas del plan vuelven a llevar hora.
 *
 * En el sistema anterior son `datetime(6)`, y sus etiquetas lo dicen con todas
 * las letras: «Fecha y Hora de Inicio», «Fecha y Hora de Fin». De 3 722 planes,
 * 3 712 tienen hora de inicio y 3 600 hora de fin, y con las dos se calcula el
 * «Tiempo Trabajado» que la ficha enseñaba en su propia tarjeta.
 *
 * Al portarlo lo declare `date`. Eso no es un detalle de tipos: trunca la hora
 * de 3 712 planes y deja sin sentido la unica medida de cuanto duro el trabajo.
 * Ademas el codigo del plan se construia a partir de la hora de inicio
 * (PE24-0412-0458 = pais, año, dia+mes, hora+minuto), asi que sin hora el
 * codigo tampoco se puede reproducir.
 *
 * Se cambia el tipo aqui y se vuelven a traer los valores con
 * `docufiz:migrate-data planes`, que es de donde salieron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_plans', function (Blueprint $table) {
            $table->dateTime('date_start')->nullable()->change();
            $table->dateTime('date_end')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('work_plans', function (Blueprint $table) {
            $table->date('date_start')->nullable()->change();
            $table->date('date_end')->nullable()->change();
        });
    }
};
