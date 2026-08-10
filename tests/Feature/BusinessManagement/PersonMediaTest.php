<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Person;
use App\Models\PersonPhoto;
use App\Services\FieldWork\SignatureService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

/**
 * La foto de referencia y la firma guardada de una persona: quien las ve.
 *
 * En el sistema anterior el administrador subia «la buena» cuando la que se
 * capturaba en obra salia irreconocible —a contraluz, con casco, en
 * movimiento—. Al portar el modulo se trajeron las firmas y esa foto se quedo
 * atras, asi que solo quedaban las capturas malas.
 *
 * El reparto que se pidio, y que es lo que se comprueba aqui:
 *
 *   super              ve la foto y la firma en la ficha, y las sube
 *   admin del tenant   NO las ve en la ficha; si ve la cara de quien firmo
 *                      dentro de un plan, que es otra cosa
 *   otros perfiles     ni una cosa ni la otra
 *
 * Y la firma NO se enseña en ninguna pantalla salvo esa ficha: al papel solo
 * llega dentro del PDF.
 */
class PersonMediaTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'people';
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['people.view_private_info', 'people.view_media'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        Storage::fake('local');
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return $this->persona();
    }

    private function persona(): Person
    {
        return Person::firstOrCreate(
            ['num_doc' => '43673535'],
            $this->base() + ['name' => 'Edison Yosimar', 'lastname' => 'Rosales Capcha', 'doc_type' => 'DNI'],
        );
    }

    /** Un PNG de verdad, chiquito: el servicio lo pasa por GD. */
    private function imagen(): UploadedFile
    {
        return UploadedFile::fake()->image('cara.png', 200, 200);
    }

    /**
     * Un PNG en memoria.
     *
     * No se usa `UploadedFile::fake()` para esto: su fichero temporal se borra
     * en cuanto el objeto se recoge, asi que leerlo del disco falla con «No
     * such file or directory» de forma intermitente. El color cambia con el
     * tono para que dos imagenes distintas tengan hashes distintos.
     */
    private function binario(int $tono = 40): string
    {
        $img = imagecreatetruecolor(200, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, $tono, 120, 200));

        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private function conMedia(): \App\Models\User
    {
        $u = $this->admin();
        $u->givePermissionTo('people.view_media');

        return $u->fresh();
    }

    /**
     * Un admin como el de produccion: todos los permisos MENOS `view_media`.
     *
     * El `admin()` de la clase base le sincroniza `Permission::all()` al rol,
     * asi que sirve para casi todo pero no para comprobar justo la excepcion.
     * Quien decide esto de verdad es `RolesAndPermissionsSeeder`, y eso tiene
     * su propia prueba en `AdminNoVeLaFotoTest`.
     */
    private function adminDeProduccion(): \App\Models\User
    {
        $rol = \Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'admin_real', 'guard_name' => 'web'], ['description' => 'admin como en produccion']);
        $rol->syncPermissions(
            \Spatie\Permission\Models\Permission::where('name', '!=', 'people.view_media')->get()
        );

        $u = \App\Models\User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($rol);

        return $u;
    }

    // ── Subir ────────────────────────────────────────────────────────────────

    public function test_se_sube_la_foto_de_referencia(): void
    {
        $persona = $this->persona();

        $this->actingAs($this->conMedia())
            ->post(route('business_management.people.media.store', [$persona->slug, 'photo']), [
                'file' => $this->imagen(),
            ])
            ->assertSessionHasNoErrors();

        $foto = $persona->fresh()->currentPhoto;

        $this->assertNotNull($foto);
        $this->assertSame(PersonPhoto::SUBIDA, $foto->source);
        Storage::disk('local')->assertExists($foto->file_path);
    }

    /**
     * Reemplazar no sobrescribe: la anterior se cierra.
     *
     * Un plan firmado hace un año tiene que poder seguir enseñando la cara con
     * la que se identifico entonces.
     */
    public function test_al_reemplazarla_la_anterior_se_jubila_en_vez_de_borrarse(): void
    {
        $persona = $this->persona();
        $servicio = app(SignatureService::class);

        $primera = $servicio->guardarFoto($persona, $this->binario(40));
        $segunda = $servicio->guardarFoto($persona, $this->binario(200));

        $this->assertNotSame($primera->id, $segunda->id);
        $this->assertNotNull($primera->fresh()->valid_to, 'La primera no se jubilo.');
        $this->assertNull($segunda->fresh()->valid_to);
        $this->assertSame($segunda->id, $persona->fresh()->currentPhoto->id);
    }

    /** La misma foto otra vez no crea una version nueva: seria ruido. */
    public function test_la_misma_foto_no_crea_otra_version(): void
    {
        $persona = $this->persona();
        $servicio = app(SignatureService::class);
        $binario = $this->binario();

        $primera = $servicio->guardarFoto($persona, $binario);
        $segunda = $servicio->guardarFoto($persona, $binario);

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, $persona->photos()->count());
    }

    // ── Quien la ve ──────────────────────────────────────────────────────────

    public function test_con_el_permiso_la_foto_se_sirve(): void
    {
        $persona = $this->persona();
        app(SignatureService::class)->guardarFoto($persona, $this->binario());

        $this->actingAs($this->conMedia())
            ->get(route('business_management.people.media', [$persona->slug, 'photo']))
            ->assertOk();
    }

    /**
     * El admin del workspace NO la ve. Es material del administrador del
     * sistema, y es el unico permiso que el admin no recibe por defecto.
     */
    public function test_el_admin_del_workspace_no_ve_la_foto_de_la_ficha(): void
    {
        $persona = $this->persona();
        app(SignatureService::class)->guardarFoto($persona, $this->binario());

        $r = $this->actingAs($this->adminDeProduccion())
            ->get(route('business_management.people.media', [$persona->slug, 'photo']));

        $this->assertNotSame(200, $r->status(), 'Un admin sin el permiso se llevo la foto.');
    }

    public function test_un_usuario_de_solo_lectura_tampoco(): void
    {
        $persona = $this->persona();
        app(SignatureService::class)->guardarFoto($persona, $this->binario());

        $r = $this->actingAs($this->soloLectura())
            ->get(route('business_management.people.media', [$persona->slug, 'photo']));

        $this->assertNotSame(200, $r->status());
    }

    /**
     * Sin foto no se sirve nada, y no revienta con un 500.
     *
     * Se comprueba que NO sea 200 en vez del 404 exacto: la aplicacion convierte
     * el 404 de un usuario autenticado en una vuelta al panel con un aviso (ver
     * `bootstrap/app.php`), asi que aqui llega un 302.
     */
    public function test_sin_foto_no_se_sirve_nada(): void
    {
        $r = $this->actingAs($this->conMedia())
            ->get(route('business_management.people.media', [$this->persona()->slug, 'photo']));

        $this->assertNotSame(200, $r->status());
        $this->assertLessThan(500, $r->status(), 'Sin foto no puede ser un error del servidor.');
    }

    /** La ficha solo nombra la foto a quien puede verla. */
    public function test_la_ficha_solo_manda_la_foto_a_quien_puede_verla(): void
    {
        $persona = $this->persona();
        app(SignatureService::class)->guardarFoto($persona, $this->binario());

        $this->actingAs($this->conMedia())
            ->get(route('business_management.people.show', $persona->slug))
            ->assertInertia(fn ($page) => $page->has('person.media.photo_url'));

        $this->actingAs($this->adminDeProduccion())
            ->get(route('business_management.people.show', $persona->slug))
            ->assertInertia(fn ($page) => $page->missing('person.media'));
    }

    // ── La primera captura se adopta ─────────────────────────────────────────

    /**
     * Sin foto, la primera que se le tome al firmar se queda como suya.
     *
     * No es la buena, pero es infinitamente mejor que nada a la hora de saber a
     * quien se esta mirando.
     */
    public function test_la_primera_foto_capturada_se_queda_como_referencia(): void
    {
        $persona = $this->persona();
        $servicio = app(SignatureService::class);

        $this->assertNull($persona->currentPhoto);

        $servicio->guardarFoto($persona, $this->binario(), PersonPhoto::CAPTURADA);

        $foto = $persona->fresh()->currentPhoto;

        $this->assertNotNull($foto);
        $this->assertSame(PersonPhoto::CAPTURADA, $foto->source);
        $this->assertFalse($foto->esDeCalidad(), 'Una captura de obra no puede pasar por la buena.');
    }

    /** Y una captura NUNCA pisa a la que subio el administrador. */
    public function test_una_captura_no_reemplaza_la_foto_subida_a_mano(): void
    {
        $persona = $this->persona();
        $servicio = app(SignatureService::class);

        $buena = $servicio->guardarFoto($persona, $this->binario(40));

        // Es lo que hace el flujo de firma cuando ya hay foto: no toca nada.
        $metodo = new \ReflectionMethod($servicio, 'adoptarFotoSiNoTiene');
        $metodo->invoke($servicio, $persona, $this->binario(200));

        $this->assertSame($buena->id, $persona->fresh()->currentPhoto->id);
        $this->assertSame(1, $persona->photos()->count());
    }
}
