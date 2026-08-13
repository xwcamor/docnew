<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registrar una cara exige consentimiento, y el consentimiento se guarda.
 *
 * EL AGUJERO
 * ----------
 * `SignatureController::enroll()` ya validaba `'consent' => ['accepted']`, pero
 * la pantalla mandaba `consent: true` a pelo: nadie preguntaba nada. Y aunque
 * se hubiera preguntado, no habia donde apuntarlo — el si de una persona a que
 * le registren la cara vivia dentro de una peticion HTTP que se descarta.
 *
 * Un dato biometrico es de los que la ley trata aparte, y lo que se pide
 * demostrar no es que el sistema pidiera permiso: es que ESTA persona lo dio,
 * CUANDO, y sobre QUE TEXTO. Sin las tres cosas no hay nada que enseñar.
 *
 * QUE SE GUARDA Y POR QUE
 * -----------------------
 *   · `consent_at`      cuando. Nulo en lo que se enrolo antes de esto y en lo
 *                       migrado: ahi no hubo consentimiento que registrar, y
 *                       poner una fecha inventada seria peor que el hueco.
 *   · `consent_version` que version del texto acepto, para poder agrupar.
 *   · `consent_text`    el TEXTO ENTERO que tenia delante. Ocupa poco y es lo
 *                       unico que responde a «¿a que dijo que si?» cuando el
 *                       texto haya cambiado tres veces desde entonces. Una
 *                       referencia a la version no vale: hay que poder leerlo
 *                       sin reconstruir que decia la aplicacion aquel dia.
 *   · `consent_ip`      desde donde. El evento de firma ya lo guarda; el
 *                       enrolamiento no guardaba nada, y es el momento MAS
 *                       delicado de los dos.
 *
 * No se toca lo ya enrolado. Quien tenga la cara registrada de antes se queda
 * con `consent_at` nulo, que es la verdad: no consta que se le preguntara. La
 * pantalla de personas puede enseñarlo y el cliente decidira si vuelve a pedir
 * el consentimiento a esa gente — que es una decision suya, no nuestra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('person_biometrics', function (Blueprint $table) {
            $table->timestamp('consent_at')->nullable()->after('enrolled_by')
                ->comment('Cuando acepto. Nulo = enrolado antes de que esto existiera');
            $table->string('consent_version', 20)->nullable()->after('consent_at')
                ->comment('Version del texto aceptado');
            $table->text('consent_text')->nullable()->after('consent_version')
                ->comment('El texto entero que tuvo delante: es lo unico que responde a «a que dijo que si»');
            $table->string('consent_ip', 45)->nullable()->after('consent_text')
                ->comment('45 caracteres: cabe una IPv6');
        });
    }

    public function down(): void
    {
        Schema::table('person_biometrics', function (Blueprint $table) {
            $table->dropColumn(['consent_at', 'consent_version', 'consent_text', 'consent_ip']);
        });
    }
};
