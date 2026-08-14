<?php

namespace Tests\Feature\Migration;

use App\Models\PersonPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El paso `archivos`: traer las fotos y firmas del sistema viejo.
 *
 * La carpeta se saca del servidor anterior ordenada POR DOCUMENTO, que es la
 * unica forma de poder revisarla a ojo antes de importarla:
 *
 *   <desde>/photo/<num_doc>/<lo que sea>.jpg
 *   <desde>/signature/<num_doc>/<lo que sea>.png
 *
 * Antes este paso buscaba el fichero plano, por el nombre que guardaba la
 * columna de la v1, y con una carpeta ordenada asi no encontraba ni una: todas
 * salian como perdidas. Y peor: iba por la tabla, asi que las personas cuya
 * firma en la v1 era la cadena `detected_by_IA` —el 96%— no tenian fila y se
 * quedaban fuera aunque su archivo estuviera ahi delante.
 */
class CopiarImagenesPorDocumentoTest extends TestCase
{
    use RefreshDatabase;

    private string $desde;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        $this->desde = sys_get_temp_dir() . '/docufiz_imagenes_' . Str::random(8);
        File::makeDirectory($this->desde, 0777, true);

        // El informe se escribe en modo anadir, para no perder el de la corrida
        // anterior. Entre pruebas eso las contamina, asi que se limpia.
        File::delete(storage_path('logs/imagenes-importadas.log'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->desde);
        File::delete(storage_path('logs/imagenes-importadas.log'));

        parent::tearDown();
    }

    /** Un PNG de verdad, para que el hash y la extension signifiquen algo. */
    private function imagen(string $sub, string $documento, string $nombre, string $semilla): void
    {
        $dir = $this->desde . '/' . $sub . '/' . $documento;

        // `force`: la misma carpeta recibe dos imagenes en la prueba de dobles.
        File::ensureDirectoryExists($dir);

        $png = imagecreatetruecolor(4, 4);
        imagefill($png, 0, 0, imagecolorallocate($png, crc32($semilla) % 255, 10, 10));
        imagepng($png, $dir . '/' . $nombre);
        imagedestroy($png);
    }

    /**
     * Una persona insertada EN CRUDO, con el documento en claro.
     *
     * Se sigue insertando por `DB::table` a proposito: asi es como quedan las
     * filas de un volcado de la v1 —sin pasar por el modelo— y este paso tiene
     * que encontrarlas igual. Lo que si hay que poner a mano es
     * `num_doc_hash`: es el indice por el que se busca desde que el documento
     * va cifrado, y un `INSERT` crudo no lo rellena solo. Sin el, la persona
     * existe y no la encuentra nadie — que es exactamente lo que arregla
     * `docufiz:cifrar-datos-sensibles` sobre lo que ya estaba en la base.
     */
    private function persona(string $numDoc, string $nombre = 'Ana'): int
    {
        return DB::table('people')->insertGetId([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1,
            'doc_type' => 'DNI', 'num_doc' => $numDoc,
            'num_doc_hash' => \App\Support\DocumentoBuscable::hash($numDoc),
            'name' => $nombre, 'lastname' => 'Prueba', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function copiar(): void
    {
        $this->artisan('docufiz:migrate-data', ['paso' => 'archivos', '--desde' => $this->desde])
            ->assertSuccessful();
    }

    /**
     * El caso que importa: la persona no tenia ninguna fila porque su firma en
     * la v1 era `detected_by_IA`, y aun asi recibe su imagen.
     */
    public function test_la_carpeta_manda_aunque_la_persona_no_tuviera_fila(): void
    {
        $id = $this->persona('00088375');

        $this->imagen('photo', '00088375', 'cualquier-nombre.png', 'foto');
        $this->imagen('signature', '00088375', 'otro.png', 'firma');

        $this->copiar();

        $foto  = DB::table('person_photos')->where('person_id', $id)->first();
        $firma = DB::table('person_signatures')->where('person_id', $id)->first();

        $this->assertNotNull($foto, 'La foto no llego a la persona.');
        $this->assertNotNull($firma, 'La firma no llego a la persona.');

        // Se renombra por su hash y se guarda en el disco privado.
        $this->assertStringStartsWith('fotos/legacy/', $foto->file_path);
        $this->assertStringStartsWith('firmas/legacy/', $firma->file_path);
        Storage::disk('local')->assertExists($foto->file_path);
        Storage::disk('local')->assertExists($firma->file_path);

        $this->assertSame(PersonPhoto::MIGRADA, $foto->source);
        $this->assertSame('migrated', $firma->source);
        $this->assertNull($foto->valid_to, 'La foto tiene que quedar vigente.');
    }

    /** El marcador que dejo el paso `personas` se corrige en sitio, no se archiva. */
    public function test_el_marcador_legacy_se_corrige_sin_dejar_historia_falsa(): void
    {
        $id = $this->persona('12345678');

        DB::table('person_photos')->insert([
            'person_id' => $id, 'file_path' => 'legacy/fotos/loquesea.webp',
            'sha256' => hash('sha256', 'loquesea.webp'), 'source' => PersonPhoto::MIGRADA,
            'valid_from' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->imagen('photo', '12345678', 'real.png', 'la buena');
        $this->copiar();

        $filas = DB::table('person_photos')->where('person_id', $id)->get();

        $this->assertCount(1, $filas, 'Un marcador que nunca fue un archivo no es historia que archivar.');
        $this->assertStringStartsWith('fotos/legacy/', $filas->first()->file_path);
        $this->assertNull($filas->first()->valid_to);
    }

    /** Una foto de verdad no se pisa: se jubila y la nueva entra al lado. */
    public function test_una_foto_real_anterior_se_jubila_en_vez_de_perderse(): void
    {
        $id = $this->persona('87654321');

        DB::table('person_photos')->insert([
            'person_id' => $id, 'file_path' => 'fotos/2026/01/anterior.webp',
            'sha256' => str_repeat('a', 64), 'source' => PersonPhoto::SUBIDA,
            'valid_from' => now()->subMonth(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->imagen('photo', '87654321', 'nueva.png', 'nueva');
        $this->copiar();

        $filas = DB::table('person_photos')->where('person_id', $id)->orderBy('id')->get();

        $this->assertCount(2, $filas);
        $this->assertNotNull($filas[0]->valid_to, 'La anterior tiene que quedar cerrada, no borrada.');
        $this->assertNull($filas[1]->valid_to);
    }

    /** Volver a correrlo no duplica nada: el hash ya coincide. */
    public function test_correrlo_dos_veces_no_duplica(): void
    {
        $id = $this->persona('11112222');
        $this->imagen('photo', '11112222', 'f.png', 'x');

        $this->copiar();
        $this->copiar();

        $this->assertSame(1, DB::table('person_photos')->where('person_id', $id)->count());
    }

    /**
     * Excel se come el cero de la izquierda de un DNI. Se acepta la carpeta sin
     * ceros **solo** si deja una sola persona.
     */
    public function test_se_perdona_el_cero_que_excel_se_comio(): void
    {
        $id = $this->persona('00088375');
        $this->imagen('photo', '88375', 'f.png', 'x');

        $this->copiar();

        $this->assertSame(1, DB::table('person_photos')->where('person_id', $id)->count());
    }

    /** Una carpeta cuyo documento no esta en la base no se le cuelga a nadie. */
    public function test_un_documento_desconocido_no_se_le_cuelga_a_nadie(): void
    {
        $this->persona('00088375');
        $this->imagen('photo', '99999999', 'f.png', 'x');

        $this->copiar();

        $this->assertSame(0, DB::table('person_photos')->count());
    }

    /**
     * Sin `--desde` la carpeta se busca sola.
     *
     * El corte de datos se hace con un solo comando y una bandera que hay que
     * acordarse de escribir es una bandera que se olvida: el paso se saltaba
     * sin ruido y la base quedaba migrada y sin una sola cara.
     */
    public function test_sin_bandera_la_carpeta_se_busca_sola(): void
    {
        $id = $this->persona('55556666');

        $dir = storage_path('app/old_system/photo/55556666');
        File::makeDirectory($dir, 0777, true);

        try {
            $png = imagecreatetruecolor(4, 4);
            imagepng($png, $dir . '/f.png');
            imagedestroy($png);

            // Sin --desde, que es exactamente como se corre setup:project --datos.
            $this->artisan('docufiz:migrate-data', ['paso' => 'archivos'])->assertSuccessful();

            $this->assertSame(1, DB::table('person_photos')->where('person_id', $id)->count());
        } finally {
            File::deleteDirectory(storage_path('app/old_system'));
        }
    }

    /**
     * La carpeta manda sobre lo que ya hubiera.
     *
     * Es la regla que pidio el dueño del producto: las imagenes de la carpeta
     * son las ultimas que se sacaron del sistema viejo, asi que son la
     * informacion buena. La anterior no se pierde — se jubila y queda como
     * historia, porque un documento firmado sigue apuntando a la que se uso.
     */
    public function test_la_carpeta_pisa_la_foto_que_ya_tenia_el_trabajador(): void
    {
        $id = $this->persona('44445555');

        DB::table('person_photos')->insert([
            'person_id' => $id, 'file_path' => 'fotos/2026/01/vieja.webp',
            'sha256' => str_repeat('b', 64), 'source' => PersonPhoto::SUBIDA,
            'valid_from' => now()->subYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->imagen('photo', '44445555', 'la-buena.png', 'nueva');
        $this->copiar();

        $vigentes = DB::table('person_photos')->where('person_id', $id)->whereNull('valid_to')->get();

        $this->assertCount(1, $vigentes, 'Solo puede quedar una vigente.');
        $this->assertStringStartsWith('fotos/legacy/', $vigentes->first()->file_path);
        $this->assertSame(1, DB::table('person_photos')->where('person_id', $id)->whereNotNull('valid_to')->count(),
            'La anterior se jubila, no se borra.');
    }

    /**
     * Con dos imagenes en la carpeta se usa una y se dice cual.
     *
     * Elegir en silencio es lo que no se puede hacer: quien armo la carpeta
     * sabe cual es la buena y desde aqui no hay forma de saberlo.
     */
    public function test_una_carpeta_con_dos_imagenes_queda_anotada(): void
    {
        $this->persona('66667777');
        $this->imagen('photo', '66667777', 'a-primera.png', 'uno');
        $this->imagen('photo', '66667777', 'z-segunda.png', 'dos');

        $this->copiar();

        $informe = file_get_contents(storage_path('logs/imagenes-importadas.log'));

        $this->assertStringContainsString('CARPETAS CON MAS DE UNA IMAGEN', $informe);
        $this->assertStringContainsString('66667777', $informe);
        $this->assertStringContainsString('a-primera.png', $informe);
        $this->assertStringContainsString('z-segunda.png', $informe);
    }

    /**
     * La misma imagen en dos documentos tambien se anota.
     *
     * Byte a byte identica en dos personas distintas: o se copio la carpeta de
     * alguien, o es la misma persona dada de alta dos veces. Se guarda igual
     * —compartiendo archivo— pero queda dicho.
     */
    public function test_la_misma_imagen_en_dos_documentos_queda_anotada(): void
    {
        $this->persona('10001000', 'Ana');
        $this->persona('20002000', 'Beto');

        // Misma semilla: mismo contenido, mismo hash.
        $this->imagen('photo', '10001000', 'f.png', 'clon');
        $this->imagen('photo', '20002000', 'f.png', 'clon');

        $this->copiar();

        $informe = file_get_contents(storage_path('logs/imagenes-importadas.log'));

        $this->assertStringContainsString('LA MISMA IMAGEN EN VARIOS DOCUMENTOS', $informe);
        $this->assertStringContainsString('10001000', $informe);
        $this->assertStringContainsString('20002000', $informe);
    }

    /** Y los documentos que no existen en la base, con nombre y apellido. */
    public function test_los_documentos_sin_persona_quedan_por_escrito(): void
    {
        $this->persona('00088375');
        $this->imagen('photo', '99999999', 'f.png', 'x');

        $this->copiar();

        $informe = file_get_contents(storage_path('logs/imagenes-importadas.log'));

        $this->assertStringContainsString('CARPETAS SIN PERSONA EN LA BASE', $informe);
        $this->assertStringContainsString('99999999', $informe);
    }

    /** Sin nada raro que contar, no se escribe informe. */
    public function test_sin_nada_que_revisar_no_se_escribe_informe(): void
    {
        $this->persona('33334444');
        $this->imagen('photo', '33334444', 'f.png', 'x');

        $this->copiar();

        $this->assertFileDoesNotExist(storage_path('logs/imagenes-importadas.log'));
    }

    /**
     * Un marcador que se quedo sin archivo se retira.
     *
     * Es la fila que dejo el paso `personas` porque la v1 decia que esa persona
     * tenia firma, y cuyo archivo no llego en la carpeta. Apunta a un fichero
     * que no existe, asi que en pantalla sale el icono de imagen rota — pero lo
     * grave es otra cosa: la pantalla de firmar la cuenta como firma y NO le
     * pide el trazo a esa persona. Firma, y su firma sigue sin existir.
     */
    public function test_un_marcador_sin_archivo_se_retira(): void
    {
        $id = $this->persona('77778888');

        DB::table('person_signatures')->insert([
            'person_id' => $id, 'file_path' => 'legacy/firmas/nunca-llego.png',
            'sha256' => hash('sha256', 'nunca-llego.png'), 'source' => 'migrated',
            'valid_from' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Su carpeta no existe: nadie le trae el archivo.
        $this->copiar();

        $this->assertSame(0, DB::table('person_signatures')->where('person_id', $id)->count(),
            'Una fila que apunta a un archivo que nunca existio no es una firma.');

        $informe = file_get_contents(storage_path('logs/imagenes-importadas.log'));
        $this->assertStringContainsString('FIRMAS DE LA V1 SIN ARCHIVO', $informe);
        $this->assertStringContainsString('77778888', $informe);
    }

    /** Y si el archivo si llega, el marcador se convierte en firma y se queda. */
    public function test_un_marcador_con_archivo_se_queda(): void
    {
        $id = $this->persona('99998888');

        DB::table('person_signatures')->insert([
            'person_id' => $id, 'file_path' => 'legacy/firmas/x.png',
            'sha256' => hash('sha256', 'x.png'), 'source' => 'migrated',
            'valid_from' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->imagen('signature', '99998888', 'firma.png', 'trazo');
        $this->copiar();

        $fila = DB::table('person_signatures')->where('person_id', $id)->first();

        $this->assertNotNull($fila);
        $this->assertStringStartsWith('firmas/legacy/', $fila->file_path);
    }

    /**
     * Mientras tanto, un marcador no cuenta como firma para el servicio.
     *
     * Es la red por debajo: vale para la base que todavia no haya vuelto a
     * importar.
     */
    public function test_un_marcador_no_cuenta_como_firma_vigente(): void
    {
        $id = $this->persona('12123434');

        DB::table('person_signatures')->insert([
            'person_id' => $id, 'file_path' => 'legacy/firmas/fantasma.png',
            'sha256' => hash('sha256', 'fantasma.png'), 'source' => 'migrated',
            'valid_from' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $persona = \App\Models\Person::find($id);

        $this->assertNull(app(\App\Services\FieldWork\SignatureService::class)->firmaVigente($persona),
            'Sin esto no se le pide el trazo y firma sin firma.');
    }

    /** Sin las carpetas no revienta: dice que no hay nada y sigue. */
    public function test_sin_carpetas_no_revienta(): void
    {
        $this->persona('00088375');

        $this->copiar();

        $this->assertSame(0, DB::table('person_photos')->count());
        $this->assertSame(0, DB::table('person_signatures')->count());
    }
}
