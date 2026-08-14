<?php

namespace Tests\Feature\Seguridad;

use App\Models\Person;
use App\Models\PersonBiometric;
use App\Support\CifradoEnReposo;
use App\Support\DocumentoBuscable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lo que un backup deja de contar, y lo que sigue funcionando a pesar de eso.
 *
 * EL AGUJERO QUE CIERRA
 * ---------------------
 * No habia una sola columna cifrada en la base. Quien abriera un volcado —el
 * `.sql` de la noche, la copia que alguien se baja para probar en local, el
 * disco del droplet, el fichero que se pasa por correo para «mirar una cosa»—
 * leia de un tiron los DNI de 14 000 personas, los 128 numeros de cada cara
 * enrolada y el texto de consentimiento que cada uno acepto.
 *
 * Los tres son datos personales y uno de ellos, la cara, **no se puede
 * revocar**: a quien le filtran una contraseña se le cambia la contraseña; a
 * quien le filtran el descriptor facial no se le cambia la cara.
 *
 * POR QUE ESTAS PRUEBAS Y NO SOLO «QUE CIFRE»
 * -------------------------------------------
 * Porque cifrar el documento es facil y romper el producto haciendolo es aun
 * mas facil, y en la direccion peligrosa: `where('num_doc', ...)` contra una
 * columna cifrada **no falla**, devuelve cero filas. O sea que el buscador de
 * la puerta diria «ese documento no esta registrado» delante de alguien que si
 * lo esta, y la comprobacion de duplicados daria via libre para dar de alta a
 * la misma persona por segunda vez. Nada de eso lanza una excepcion ni sale en
 * un log: solo se nota en obra, semanas despues.
 *
 * Asi que aqui se fijan las dos mitades a la vez: que el dato no se puede leer
 * desde la base, y que la aplicacion lo sigue encontrando.
 *
 * LO QUE ESTO **NO** PROTEGE, y conviene que este escrito al lado del codigo:
 * el cifrado usa el `APP_KEY` de la aplicacion. Quien tenga esa clave —quien
 * entre al servidor y lea el `.env`— lo descifra todo. Esto cierra el agujero
 * del backup, no el del servidor. Y si el `APP_KEY` se pierde, estos datos no
 * vuelven: ni los documentos ni la posibilidad de buscar por ellos.
 */
class CifradoEnReposoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
    }

    // ── El documento ────────────────────────────────────────────────────────

    /** Lo que ve quien abre el backup: un sobre, no un DNI. */
    public function test_el_documento_no_esta_en_claro_en_la_base(): void
    {
        $persona = $this->persona('47019236');

        $crudo = DB::table('people')->where('id', $persona->id)->value('num_doc');

        $this->assertNotSame('47019236', $crudo, 'el documento sigue en claro en la base');
        $this->assertStringNotContainsString('47019236', (string) $crudo);
        $this->assertTrue(CifradoEnReposo::estaCifrado($crudo));

        // Y la aplicacion lo lee igual: cifrar sin poder leer no es cifrar, es
        // perder.
        $this->assertSame('47019236', $persona->fresh()->num_doc);
    }

    /**
     * La consulta de siempre sigue encontrando a la persona.
     *
     * `where('num_doc', ...)` la traduce `PersonQueryBuilder` al indice ciego.
     * Es la pieza que evita que los veinte sitios que consultan por documento
     * se rompan en silencio, uno a uno, segun se vayan tocando.
     */
    public function test_se_sigue_encontrando_a_una_persona_por_su_documento(): void
    {
        $persona = $this->persona('47019236');
        $this->persona('10000001', 'Otro');

        $this->assertSame($persona->id, Person::where('num_doc', '47019236')->value('id'));
        $this->assertNull(Person::where('num_doc', '99999999')->first());
        $this->assertSame(1, Person::whereIn('num_doc', ['47019236', 'nadie'])->count());
    }

    /**
     * El mismo documento escrito de otra forma es el mismo documento.
     *
     * En obra el DNI llega del lector, copiado de un Excel y tecleado a mano, y
     * cada via lo escribe distinta. Con un indice ciego eso deja de ser una
     * molestia y pasa a ser un fallo: dos escrituras distintas dan dos hashes
     * distintos y la segunda no encuentra a la primera. Por eso se normaliza
     * antes de hashear — trim, mayusculas y fuera todo lo que no sea letra o
     * cifra.
     */
    public function test_los_puntos_los_guiones_y_los_espacios_no_cambian_a_quien_encuentra(): void
    {
        $persona = $this->persona('47019236');

        foreach (['47.019.236', '47 019 236', '47-019-236', ' 47019236 '] as $escrito) {
            $this->assertSame(
                $persona->id,
                Person::where('num_doc', $escrito)->value('id'),
                "«{$escrito}» tendria que encontrar al mismo DNI",
            );
        }

        // Y las mayusculas tampoco: un pasaporte es el mismo se escriba como se
        // escriba. Antes «ab123456» y «AB123456» eran dos personas distintas
        // para la base, que no lo son para nadie mas.
        $pasaporte = $this->persona('AB123456', 'Zoe');

        $this->assertSame($pasaporte->id, Person::where('num_doc', 'ab123456')->value('id'));
    }

    /**
     * Los ceros de la izquierda SI cuentan, y esto lo fija a proposito.
     *
     * Es la tentacion obvia al normalizar —«003028674» y «3028674» parecen lo
     * mismo— y seria un error: en un carne de extranjeria el cero de delante es
     * parte del numero. Fusionarlos juntaria a dos personas reales bajo la
     * misma identidad, que es peor que no encontrar a una.
     */
    public function test_los_ceros_de_la_izquierda_no_se_quitan_al_normalizar(): void
    {
        $conCero = $this->persona('003028674');
        $sinCero = $this->persona('3028674', 'Otro');

        $this->assertNotSame($conCero->id, $sinCero->id);
        $this->assertSame($conCero->id, Person::where('num_doc', '003028674')->value('id'));
        $this->assertSame($sinCero->id, Person::where('num_doc', '3028674')->value('id'));
    }

    /**
     * Una busqueda parcial LANZA en vez de devolver una lista vacia.
     *
     * Es la unica consulta por documento que el indice ciego no puede
     * responder, y la forma de fallar importa mas que el fallo: un
     * `LIKE '%4701%'` que devuelve cero filas parece un resultado legitimo y se
     * despliega. Un error se ve en la primera prueba.
     */
    public function test_buscar_un_trozo_del_documento_falla_a_gritos(): void
    {
        $this->persona('47019236');

        $this->expectException(\LogicException::class);

        Person::where('num_doc', 'like', '%4701%')->get();
    }

    /**
     * El indice ciego NO viaja al navegador.
     *
     * `num_doc` ya estaba tapado en la serializacion; el hash es un dato
     * derivado del mismo dato tapado y tiene que irse por la misma puerta. Un
     * DNI peruano son ocho cifras: quien tenga el hash y ademas la clave las
     * prueba todas en un rato, y aun sin la clave, dos hashes iguales delatan
     * que dos filas son la misma persona.
     */
    public function test_ni_el_documento_ni_su_indice_salen_en_el_json(): void
    {
        $persona = $this->persona('47019236');

        $serializado = $persona->fresh()->toArray();

        $this->assertArrayNotHasKey('num_doc', $serializado);
        $this->assertArrayNotHasKey('num_doc_hash', $serializado);
        $this->assertStringNotContainsString('47019236', json_encode($serializado));
    }

    /**
     * El indice esta CERRADO CON CLAVE, no es un `sha256()` pelado.
     *
     * Con un hash sin clave, quien se lleve la tabla prueba los cien millones
     * de DNI posibles en unos segundos en un portatil y reconstruye el padron
     * entero: el cifrado de la columna de al lado no habria servido de nada. El
     * HMAC obliga a tener ademas la clave, que vive en el `.env`.
     */
    public function test_el_indice_depende_de_la_clave_de_la_aplicacion(): void
    {
        $conLaDeHoy = DocumentoBuscable::hash('47019236');

        $this->assertNotSame(hash('sha256', '47019236'), $conLaDeHoy,
            'el indice no puede ser un sha256 a secas: se rompe por fuerza bruta');

        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);

        $this->assertNotSame($conLaDeHoy, DocumentoBuscable::hash('47019236'),
            'con otra clave tiene que salir otro indice — si no, la clave no pinta nada');
    }

    /**
     * Guardar un modelo con el SELECT recortado NO borra el indice.
     *
     * «Editar todo», el contador del exportador y media docena de consultas
     * mas hidratan la persona sin `num_doc`. Si el modelo recalculara el indice
     * a ciegas en cada `save()`, ahi escribiria un nulo encima del bueno: la
     * persona seguiria en la base con su documento intacto y dejaria de
     * encontrarla el buscador de la puerta. Sin error, sin rastro y sin forma
     * de saber a cuantas les paso.
     */
    public function test_guardar_sin_el_documento_cargado_no_borra_el_indice(): void
    {
        $persona = $this->persona('47019236');
        $esperado = DB::table('people')->where('id', $persona->id)->value('num_doc_hash');

        $recortada = Person::query()->select('id', 'name', 'is_active')->find($persona->id);
        $recortada->name = 'Cambiada';
        $recortada->save();

        $this->assertSame($esperado, DB::table('people')->where('id', $persona->id)->value('num_doc_hash'));
        $this->assertSame($persona->id, Person::where('num_doc', '47019236')->value('id'));
    }

    // ── La cara y el consentimiento ─────────────────────────────────────────

    /** Ni los 128 numeros de la cara ni el texto aceptado quedan legibles. */
    public function test_la_biometria_y_el_consentimiento_no_estan_en_claro(): void
    {
        $persona = $this->persona('47019236');

        $descriptor = array_map(fn (int $i) => $i / 1000, range(1, 128));

        $biometria = PersonBiometric::create([
            'person_id'       => $persona->id,
            'face_descriptor' => [$descriptor],
            'enrolled_at'     => now(),
            'is_active'       => true,
            'consent_at'      => now(),
            'consent_version' => PersonBiometric::CONSENT_VERSION,
            'consent_text'    => 'Acepto que registren mi cara.',
        ]);

        $fila = DB::table('person_biometrics')->where('id', $biometria->id)->first();

        $this->assertTrue(CifradoEnReposo::estaCifrado($fila->face_descriptor));
        $this->assertTrue(CifradoEnReposo::estaCifrado($fila->consent_text));
        $this->assertStringNotContainsString('0.001', (string) $fila->face_descriptor);
        $this->assertStringNotContainsString('Acepto', (string) $fila->consent_text);

        // Y la verificacion 1:1 sigue recibiendo un ARRAY de numeros, no una
        // cadena: si esto se rompiera, el bucle que compara caras se quedaria
        // callado en vez de comparar y todo el mundo firmaria sin reconocer.
        $releida = $biometria->fresh();

        $this->assertIsArray($releida->face_descriptor);
        $this->assertCount(128, $releida->face_descriptor[0]);
        $this->assertSame($descriptor, $releida->face_descriptor[0]);
        $this->assertSame('Acepto que registren mi cara.', $releida->consent_text);
    }

    /**
     * Un consentimiento que no consta se queda sin constar.
     *
     * `consent_text` es nulo en todo lo enrolado antes de que el consentimiento
     * se guardara, y en todo lo migrado. Cifrar el hueco produciria un sobre
     * que al abrirse da una cadena vacia, o sea convertiria «no consta» en
     * «acepto un texto en blanco». No es lo mismo y es justo lo que hay que
     * poder enseñar en una auditoria.
     */
    public function test_lo_que_no_consta_no_se_convierte_en_un_texto_vacio(): void
    {
        $persona = $this->persona('47019236');

        $biometria = PersonBiometric::create([
            'person_id' => $persona->id, 'face_descriptor' => [[0.1]],
            'enrolled_at' => now(), 'is_active' => true,
        ]);

        $this->assertNull(DB::table('person_biometrics')->where('id', $biometria->id)->value('consent_text'));
        $this->assertNull($biometria->fresh()->consent_text);
    }

    // ── El comando que migra lo que ya existe ───────────────────────────────

    /**
     * Lo que ya estaba en la base —14 000 personas— se cifra sin tocar nada mas.
     *
     * Las filas se meten aqui EN CRUDO, con el documento en claro y sin indice:
     * es exactamente el estado del que se viene, y el que la aplicacion se
     * encuentra el dia del despliegue.
     */
    public function test_el_comando_cifra_lo_que_estaba_en_claro_y_rellena_el_indice(): void
    {
        $id = $this->personaEnCrudo('47019236');

        $this->artisan('docufiz:cifrar-datos-sensibles')->assertSuccessful();

        $fila = DB::table('people')->where('id', $id)->first();

        $this->assertTrue(CifradoEnReposo::estaCifrado($fila->num_doc));
        $this->assertSame(DocumentoBuscable::hash('47019236'), $fila->num_doc_hash);

        // Y lo que de verdad importa: despues de migrar, la puerta encuentra a
        // esa persona.
        $this->assertSame($id, Person::where('num_doc', '47019236')->value('id'));
    }

    /**
     * Se puede volver a lanzar. Esta es la prueba que evita la perdida de datos.
     *
     * Un comando que toca 14 000 filas se corta —se cae la conexion, se acaba
     * el tiempo, alguien pulsa Ctrl+C— y hay que poder relanzarlo. Si en la
     * segunda pasada volviera a cifrar lo ya cifrado, el descifrado devolveria
     * el sobre de dentro en vez del DNI: no un error, un dato ilegible para
     * siempre y sin aviso.
     */
    public function test_correr_el_comando_dos_veces_no_cifra_dos_veces(): void
    {
        $id = $this->personaEnCrudo('47019236');

        $this->artisan('docufiz:cifrar-datos-sensibles')->assertSuccessful();
        $primero = DB::table('people')->where('id', $id)->value('num_doc');

        $this->artisan('docufiz:cifrar-datos-sensibles')->assertSuccessful();
        $segundo = DB::table('people')->where('id', $id)->value('num_doc');

        $this->assertSame($primero, $segundo, 'la segunda pasada reescribio una fila ya hecha');
        $this->assertSame('47019236', Person::find($id)->num_doc);
    }

    /** El ensayo cuenta lo que haria y no escribe ni una fila. */
    public function test_el_ensayo_no_escribe_nada(): void
    {
        $id = $this->personaEnCrudo('47019236');

        $this->artisan('docufiz:cifrar-datos-sensibles', ['--dry-run' => true])
            ->expectsOutputToContain('Ensayo')
            ->assertSuccessful();

        $fila = DB::table('people')->where('id', $id)->first();

        $this->assertSame('47019236', $fila->num_doc, 'el ensayo escribio en la base');
        $this->assertNull($fila->num_doc_hash);
    }

    /**
     * Una fila a la que solo le falta el indice se arregla, aunque ya este cifrada.
     *
     * Son dos estados independientes: la aplicacion escribe las dos cosas a la
     * vez, pero un `INSERT` crudo —un sembrado, un volcado— puede dejar una y
     * no la otra. Sin indice, esa persona existe en la base y no la encuentra
     * nadie, que es la averia mas silenciosa de todas.
     */
    public function test_el_comando_rellena_el_indice_que_falta_sin_volver_a_cifrar(): void
    {
        $id = $this->personaEnCrudo('47019236');

        // Cifrada a mano y sin indice: el estado intermedio de un despliegue.
        DB::table('people')->where('id', $id)->update([
            'num_doc'      => \Illuminate\Support\Facades\Crypt::encryptString('47019236'),
            'num_doc_hash' => null,
        ]);
        $cifradoAntes = DB::table('people')->where('id', $id)->value('num_doc');

        $this->artisan('docufiz:cifrar-datos-sensibles')->assertSuccessful();

        $fila = DB::table('people')->where('id', $id)->first();

        $this->assertSame($cifradoAntes, $fila->num_doc, 'no habia que volver a cifrarla');
        $this->assertSame(DocumentoBuscable::hash('47019236'), $fila->num_doc_hash);
    }

    /**
     * Lo que se cifro con OTRA clave se cuenta y se deja quieto.
     *
     * Es el sintoma de un `APP_KEY` rotado sin re-cifrar, y escribir encima
     * seria destruir el unico dato que quedaba. El comando termina en fallo
     * a proposito: hay que enterarse ahora, no el dia que alguien abra la ficha
     * de esa persona.
     */
    public function test_lo_que_no_abre_con_la_clave_de_hoy_no_se_pisa(): void
    {
        $id = $this->personaEnCrudo('47019236');

        // Un sobre con la forma buena y el contenido de otra clave.
        $ajeno = (new \Illuminate\Encryption\Encrypter(random_bytes(32), config('app.cipher')))
            ->encryptString('47019236');

        DB::table('people')->where('id', $id)->update(['num_doc' => $ajeno, 'num_doc_hash' => null]);

        $this->artisan('docufiz:cifrar-datos-sensibles')->assertFailed();

        $this->assertSame($ajeno, DB::table('people')->where('id', $id)->value('num_doc'),
            'el comando piso un dato que no sabia leer');
    }

    // ── Decorado ────────────────────────────────────────────────────────────

    private function persona(string $numDoc, string $nombre = 'Ana'): Person
    {
        return Person::create([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1,
            'doc_type' => 'DNI', 'num_doc' => $numDoc,
            'name' => $nombre, 'lastname' => 'Prueba', 'is_active' => true,
        ]);
    }

    /** Como estaba la base antes de todo esto: documento en claro, sin indice. */
    private function personaEnCrudo(string $numDoc, string $nombre = 'Ana'): int
    {
        return DB::table('people')->insertGetId([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1,
            'doc_type' => 'DNI', 'num_doc' => $numDoc, 'num_doc_hash' => null,
            'name' => $nombre, 'lastname' => 'Prueba', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * `setup:project` cifra y calcula el indice ciego el solo.
     *
     * La migracion crea `people.num_doc_hash` VACIA —rellenarla es recorrer
     * 14 000 filas y eso no se hace dentro de una migracion— y hasta que se
     * rellena, buscar a alguien por su documento **no encuentra a nadie**. No
     * falla: no encuentra, que es peor, porque parece que esa persona no esta
     * dada de alta y el gesto siguiente en la puerta es darla de alta otra vez.
     *
     * Dejar eso dependiendo de que alguien recuerde un segundo comando es
     * dejarlo roto. Se comprueba leyendo el fichero y no ejecutandolo porque
     * `setup:project` empieza con un `migrate:fresh`: correrlo aqui vaciaria la
     * base de la propia suite.
     */
    public function test_el_setup_del_proyecto_cifra_sin_que_nadie_se_lo_pida(): void
    {
        $comando = file_get_contents(app_path('Console/Commands/SetupProjectCommand.php'));

        $this->assertStringContainsString("docufiz:cifrar-datos-sensibles", $comando);

        // Y DESPUES de traer la v1: la importacion mete miles de personas y
        // tienen que quedar cifradas y con su hash igual que las demas.
        $this->assertTrue(
            strpos($comando, 'traerDatosDeLaV1()') < strpos($comando, '$this->cifrarLoSensible()'),
            'lo importado de la v1 tambien tiene que quedar cifrado',
        );
    }
}
