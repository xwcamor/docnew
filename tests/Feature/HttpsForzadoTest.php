<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

/**
 * La cookie de sesión viajaba en claro si algo caía a http.
 *
 * Eran dos cabos sueltos que dependían los dos de que nadie se equivocara al
 * configurar el servidor:
 *
 *   - No había `forceScheme` en ningún provider. Laravel arma sus URLs con el
 *     esquema de la petición que las pidió, así que una petición que entre por
 *     http —un enlace antiguo, un redirect mal puesto delante, un `curl`—
 *     devuelve un formulario de login que postea a http, con la contraseña
 *     dentro del cuerpo.
 *
 *   - `SESSION_SECURE_COOKIE` no estaba en el `.env`, así que
 *     `config('session.secure')` valía null y Laravel lo lee como «no». Sin el
 *     flag `secure`, el navegador manda la cookie de sesión también por http.
 *     Una sola petición que caiga a http basta para que la sesión entera pase
 *     en claro por la red, y con esa cookie se entra sin necesitar contraseña.
 *
 * Se cierra desde la aplicación y no desde el `.env`, que es lo que hacía falta
 * de verdad: un `.env` de producción se escribe una vez y nadie lo vuelve a
 * mirar, y el día que se despliegue en otro sitio o alguien copie el de
 * desarrollo, el agujero vuelve solo.
 *
 * La otra mitad de la prueba es la que evita romperle el día a quien
 * desarrolla: en local se corre en http://localhost:8000 con `php artisan
 * serve` y sin certificado. Forzar https ahí manda el navegador a
 * https://localhost:8000, que no contesta a nada.
 */
class HttpsForzadoTest extends TestCase
{
    /** El provider decidiendo con el entorno que se le ponga delante. */
    protected function decidirCon(string $entorno): void
    {
        $this->app['env'] = $entorno;

        (new AppServiceProvider($this->app))->forzarHttpsFueraDeLocal();
    }

    /**
     * En producción, https y cookie `secure`, venga lo que venga en el `.env`.
     *
     * Se apaga `session.secure` a mano antes de llamar, que es el caso peor y
     * el más probable: `SESSION_SECURE_COOKIE=false` copiado del `.env` de
     * desarrollo. La aplicación lo pisa igual — fuera de local no hay caso
     * legítimo de cookie de sesión sin `secure`.
     */
    public function test_en_produccion_fuerza_https_y_la_cookie_segura(): void
    {
        config(['session.secure' => false]);

        $this->decidirCon('production');

        $this->assertSame('https', parse_url(url('/'), PHP_URL_SCHEME));
        $this->assertTrue(config('session.secure'));
    }

    /**
     * Un entorno nuevo nace protegido.
     *
     * La condición nombra `local` y `testing` y deja fuera todo lo demás, en
     * vez de preguntar por `isProduction()`. Es a propósito: con `isProduction`
     * hay que acordarse de añadir cada staging, cada demo y cada preview, y
     * quien monte el siguiente no va a saber que existe esta línea.
     */
    public function test_un_entorno_que_no_es_local_tambien_queda_protegido(): void
    {
        $this->decidirCon('staging');

        $this->assertSame('https', parse_url(url('/'), PHP_URL_SCHEME));
        $this->assertTrue(config('session.secure'));
    }

    /**
     * En local NO se fuerza, y ese es el motivo de que la condición exista.
     *
     * Con https forzado, `php artisan serve` deja de servir de nada: el
     * navegador se va a https://localhost:8000 y no hay quien conteste. Sería
     * dejar sin poder trabajar a quien mantiene esto, para arreglar un problema
     * que en su portátil no existe.
     */
    public function test_en_local_no_se_fuerza_nada(): void
    {
        config(['session.secure' => null]);

        $this->decidirCon('local');

        $this->assertSame('http', parse_url(url('/'), PHP_URL_SCHEME));
        $this->assertNull(config('session.secure'));
    }

    /**
     * Y en `testing` tampoco: es este mismo conjunto de pruebas.
     *
     * Corre sobre http://localhost y comprueba redirecciones por su URL
     * completa. Si el provider forzara https aquí, no se caería el sistema —se
     * caerían las 1600 pruebas, que es peor, porque una suite roja deja de
     * avisar de nada.
     */
    public function test_el_conjunto_de_pruebas_sigue_corriendo_sobre_http(): void
    {
        $this->assertSame('testing', $this->app->environment());
        $this->assertSame('http', parse_url(url('/'), PHP_URL_SCHEME));
    }
}
