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
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->desde);

        parent::tearDown();
    }

    /** Un PNG de verdad, para que el hash y la extension signifiquen algo. */
    private function imagen(string $sub, string $documento, string $nombre, string $semilla): void
    {
        $dir = $this->desde . '/' . $sub . '/' . $documento;
        File::makeDirectory($dir, 0777, true);

        $png = imagecreatetruecolor(4, 4);
        imagefill($png, 0, 0, imagecolorallocate($png, crc32($semilla) % 255, 10, 10));
        imagepng($png, $dir . '/' . $nombre);
        imagedestroy($png);
    }

    private function persona(string $numDoc, string $nombre = 'Ana'): int
    {
        return DB::table('people')->insertGetId([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1,
            'doc_type' => 'DNI', 'num_doc' => $numDoc,
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

    /** Sin las carpetas no revienta: dice que no hay nada y sigue. */
    public function test_sin_carpetas_no_revienta(): void
    {
        $this->persona('00088375');

        $this->copiar();

        $this->assertSame(0, DB::table('person_photos')->count());
        $this->assertSame(0, DB::table('person_signatures')->count());
    }
}
