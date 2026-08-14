<?php

namespace Tests;

use App\Models\Plan;
use App\Models\Setting;
use App\Scopes\HideSuperScope;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\DetectaColumnasInventadas;

abstract class TestCase extends BaseTestCase
{
    use DetectaColumnasInventadas;

    protected function setUp(): void
    {
        parent::setUp();

        // Una columna que no existe pasa desapercibida en SQLite —lee las
        // comillas dobles como texto— y revienta en PostgreSQL. Asi se colo el
        // panel roto con las 1212 pruebas en verde.
        $this->vigilarColumnasInventadas();

        // La cache estatica de Plan::findBySlug() persiste entre tests dentro
        // del mismo proceso PHP. RefreshDatabase no la resetea — la limpiamos
        // aca para que cada test arranque con cache limpia.
        Plan::flushCache();

        // Mismo problema: HideSuperScope cachea los role ids (super/api) de forma
        // estatica. Con RefreshDatabase los roles se recrean con IDs nuevos por
        // test; sin resetear, el scope filtraria por el id viejo (rol equivocado).
        HideSuperScope::flushCache();

        // Y el tercero: Setting::get() cachea cada clave en un array estatico
        // para no ir a la base en cada llamada dentro de una peticion. En las
        // pruebas ese «dentro de una peticion» es TODO el proceso, asi que una
        // clase que apaga un ajuste para comprobar que se respeta —el historial
        // de accesos, sin ir mas lejos— se lo deja apagado a la clase
        // siguiente, que arranca con la base limpia y no tiene como enterarse.
        Setting::flushCache();
    }

    protected function tearDown(): void
    {
        // Se MIRA antes de desmontar —despues ya no hay conexion que preguntar—
        // y se FALLA despues, o el tearDown se corta a la mitad y la
        // transaccion de RefreshDatabase se queda abierta: la prueba siguiente
        // muere con «cannot start a transaction within a transaction».
        //
        // Y no dentro del oyente: fallar en medio de la peticion la convierte
        // en un 500 y lo que se lee es «Not a valid Inertia response».
        $inventadas = $this->columnasInventadasDetectadas();

        parent::tearDown();

        $this->denunciarColumnasInventadas($inventadas);
    }

    /**
     * ¿Existe en `people` alguien con este documento?
     *
     * `assertDatabaseHas('people', ['num_doc' => '47019236'])` dejo de valer el
     * dia que la columna paso a estar cifrada: compara un DNI contra un sobre
     * distinto en cada fila y no encuentra nunca nada. Y como la comprobacion
     * es «no existe», el fallo se lee como si la aplicacion no hubiera guardado
     * a nadie, que es justo el diagnostico equivocado.
     *
     * Se pregunta por el indice ciego, que es lo que la aplicacion consulta de
     * verdad. Comprobar por ahi tiene ademas un efecto util: si algun dia el
     * modelo dejara de rellenar `num_doc_hash`, todas estas pruebas caerian a
     * la vez — y esa es exactamente la averia que dejaria a una persona dada de
     * alta e imposible de encontrar en la puerta.
     *
     * @param  array<string, mixed>  $ademas  otras columnas que tienen que cuadrar
     */
    protected function assertPersonaConDocumento(string $documento, array $ademas = []): void
    {
        $this->assertDatabaseHas('people', $ademas + [
            'num_doc_hash' => \App\Support\DocumentoBuscable::hash($documento),
        ]);
    }

    /** El reverso: que ese documento NO haya entrado. @param array<string, mixed> $ademas */
    protected function assertSinPersonaConDocumento(string $documento, array $ademas = []): void
    {
        $this->assertDatabaseMissing('people', $ademas + [
            'num_doc_hash' => \App\Support\DocumentoBuscable::hash($documento),
        ]);
    }
}
