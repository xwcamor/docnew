<?php

namespace Tests\Feature\FieldWork;

use App\Models\ApprovalRule;
use App\Models\Company;
use App\Models\EvidenceFile;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Person;
use App\Models\PersonRole;
use App\Models\ReportSigner;
use App\Models\SignatureEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkPlanApproval;
use App\Models\WorkPlanPerson;
use App\Models\WorkType;
use App\Services\FieldWork\FormSubmissionPdfService;
use App\Services\FieldWork\FormSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El PDF firmado: el unico eslabon del flujo que sale del sistema.
 *
 * Todo lo demas —llenar, firmar, revisar— vive dentro de la aplicacion y se
 * puede corregir. El PDF es lo que la empresa archiva y lo que le ensena a un
 * inspector dos anios despues, asi que lo que se comprueba aqui es que salga
 * completo: el membrete del workspace, el plan, el formato en la version con la
 * que se lleno, la foto del papel, cada firma con su cara y su metodo, y las
 * que quedaron pendientes marcadas como tales.
 */
class FormSubmissionPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('plans')->insertOrIgnore([['id' => 1, 'slug' => 'enterprise', 'name' => 'Enterprise', 'sort_order' => 1, 'max_users' => -1, 'max_records_per_module' => -1, 'export_rate_limit' => 50, 'support_level' => 'priority', 'features' => json_encode(['team_management' => true]), 'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'USD', 'is_active' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Contratistas del Sur', 'address' => 'Av. Los Talleres 120, Lurin', 'report_disclaimer' => 'Documento generado por DOCUFIZ. Su validez depende de las firmas registradas.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('subscriptions')->insertOrIgnore([['id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD', 'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['form_submissions.view', 'form_submissions.export', 'people.view_private_info'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
    }

    public function test_el_pdf_trae_el_membrete_el_plan_el_formato_y_las_firmas(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        app()->setLocale('es');

        $escenario = $this->escenario();

        $pdf = app(FormSubmissionPdfService::class)
            ->generar($escenario['entrega'], $escenario['usuario'])
            ->output();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf), 'un PDF con fotos incrustadas no puede pesar tan poco');

        // El logo y la cara van incrustados como objetos de imagen del PDF.
        $this->assertGreaterThanOrEqual(2, substr_count($pdf, '/Subtype /Image'));

        // Guarda contra la regresion de maquetado que ya se comio 11 paginas:
        // un float dentro del pie fijo hace que DomPDF pagine por bloque.
        $this->assertLessThanOrEqual(3, preg_match_all('#/Type /Page[^s]#', $pdf));

        $texto = $this->textoDelPdf($pdf);

        // 1 · Membrete: la marca, el TITULO del formato y el descargo.
        //
        // Este workspace tiene logo, asi que el NOMBRE no se imprime: el logo
        // ya dice de quien es el documento —para eso se sube— y repetirlo
        // debajo es decirlo dos veces en la esquina donde menos sitio hay. El
        // caso sin logo tiene su propia prueba.
        //
        // La direccion tampoco: el sitio que importa en un formato de obra es
        // donde se hizo el trabajo, y ese va en la cabecera del plan.
        //
        // Y el titulo es el NOMBRE del documento, no su sigla: en la esquina
        // salia «AST» y el nombre completo no aparecia en ningun sitio.
        $this->assertStringNotContainsString('Contratistas del Sur', $texto);
        $this->assertStringNotContainsString('Av. Los Talleres 120', $texto);
        $this->assertStringContainsString('Su validez depende de las firmas', $texto);
        $this->assertStringContainsString('ANALISIS DE SEGURIDAD EN EL TRABAJO', $texto);

        // Y el estado no se imprime: es informacion del sistema, y ademas se
        // quedaria congelado en el papel el dia que se genero.
        $this->assertStringNotContainsString('Confirmado', $texto);

        // 2 · Cabecera del plan.
        $this->assertStringContainsString($escenario['plan']->code, $texto);
        $this->assertStringContainsString('08072026', $texto);            // orden de trabajo
        $this->assertStringContainsString('Electro Andina SAC', $texto);  // contratista
        $this->assertStringContainsString('20481234567', $texto);         // RUC
        $this->assertStringContainsString('Mantenimiento preventivo', $texto);

        // 3 · El formato tal como se lleno: simples y compuestos.
        $this->assertStringContainsString('Limpieza de celda 3', $texto);   // text
        $this->assertStringContainsString('Casco, Guantes', $texto);        // multiselect
        $this->assertStringContainsString('Trabajo en altura', $texto);     // fila de la matriz
        $this->assertStringContainsString('Arnes anclado', $texto);         // otra columna de la matriz
        $this->assertStringContainsString('Ana Quispe', $texto);            // fila del checklist por persona

        // 5 · Firmas: nombre, documento, rol, metodo y la marca de pendiente.
        $this->assertStringContainsString('40000001', $texto);
        $this->assertStringContainsString('Reconocimiento facial', $texto);
        $this->assertStringContainsString('Captura por tiempo de espera', $texto);
        $this->assertStringContainsString('PENDIENTE DE REVISI', $texto);

        // 6 · Firmas formales del workspace, con su relacion traducida (la hoja
        // de estilos las pone en mayusculas).
        $this->assertStringContainsString('Jefa de Seguridad', $texto);
        $this->assertStringContainsString('APROBADO POR', $texto);
        $this->assertStringContainsString('ELABORADO POR', $texto);

        // 7 · Identificador verificable de la entrega.
        $this->assertStringContainsString($escenario['entrega']->slug, $texto);
    }

    /** La foto del papel es el documento: tiene que salir incrustada. */
    public function test_el_formato_de_solo_subida_incrusta_la_foto_del_papel(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $escenario = $this->escenario();
        $hoja = $this->hojaX($escenario['plan']);

        $datos = app(FormSubmissionPdfService::class)->datos($hoja, $escenario['usuario']);

        $this->assertCount(1, $datos['adjuntos']);
        $this->assertTrue($datos['adjuntos'][0]['pagina_completa']);
        $this->assertStringStartsWith('data:image/', $datos['adjuntos'][0]['imagen']);

        $pdf = app(FormSubmissionPdfService::class)->generar($hoja, $escenario['usuario'])->output();
        $this->assertStringStartsWith('%PDF-', $pdf);
        // El papel va en su propia pagina, detras del cuerpo del documento.
        $this->assertSame(2, preg_match_all('#/Type /Page[^s]#', $pdf));
        // Logo, cara de la firma y foto del papel. Son tres imagenes, pero la
        // cara va en WebP y DomPDF la convierte a PNG con su mascara aparte,
        // asi que el conteo de objetos no es exactamente tres.
        $this->assertGreaterThanOrEqual(3, substr_count($pdf, '/Subtype /Image'));
    }

    /**
     * Publicar una version nueva del formato no puede cambiar lo ya firmado.
     *
     * Se fuerza el caso raro —la entrega apunta a la fila de la v2 pero declara
     * `template_version` 1— porque es el que llega con los datos migrados, y es
     * justo donde la version congelada deja de ser la fila apuntada.
     */
    public function test_se_pinta_la_version_congelada_y_no_la_vigente(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $escenario = $this->escenario();
        $v1 = $escenario['plantilla'];

        $v2 = FormTemplate::create([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1,
            'code' => $v1->code, 'kind' => FormTemplate::STRUCTURED, 'status' => 'published',
            'version' => 2, 'requires_signature' => true, 'published_at' => now(),
        ]);
        $seccionV2 = FormSection::create(['form_template_id' => $v2->id, 'position' => 1]);
        FormField::create([
            'form_section_id' => $seccionV2->id, 'code' => 'campo_de_la_version_nueva',
            'field_type' => 'text', 'position' => 1,
        ]);

        $escenario['entrega']->forceFill(['form_template_id' => $v2->id])->save();

        $datos = app(FormSubmissionPdfService::class)
            ->datos($escenario['entrega']->fresh(), $escenario['usuario']);

        $codigos = collect($datos['secciones'])->flatMap(fn ($s) => collect($s['campos'])->pluck('codigo'));

        $this->assertContains('actividad', $codigos->all());
        $this->assertNotContains('campo_de_la_version_nueva', $codigos->all());
        $this->assertSame(1, $datos['formato']['version']);
    }

    /**
     * Los compuestos se pintan como tabla, con la forma que emite la pantalla.
     *
     * Es el contrato con `resources/js/Components/FormFields`: si el llenado
     * cambia la forma del JSON, aqui se ve. Y lo que no se imprime tambien
     * cuenta: el `person_slug` es un identificador interno y no pinta nada en
     * un documento que se archiva.
     */
    public function test_los_compuestos_se_pintan_como_los_guarda_la_pantalla(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        app()->setLocale('es');

        $escenario = $this->escenario();
        $entrega = $escenario['entrega'];

        // El escenario devuelve la entrega ya cerrada, y esta prueba le anade
        // tres campos mas. Se reabre igual que lo haria una persona desde la
        // pantalla, en vez de escribir sobre un formato confirmado por detras.
        app(FormSubmissionService::class)->reabrir($entrega);

        $seccion = FormSection::create(['form_template_id' => $escenario['plantilla']->id, 'position' => 3]);

        $seccion->fields()->create([
            'code' => 'epp_real', 'field_type' => 'person_checklist', 'position' => 1,
            'config' => ['items' => ['Casco', 'Guantes']],
        ]);
        $seccion->fields()->create([
            'code' => 'preguntas', 'field_type' => 'question_bank', 'position' => 2,
            // El catalogo tiene las MISMAS dos preguntas que se contestan mas
            // abajo. Antes traia un «¿Detente?» suelto que no casaba con
            // ninguna respuesta: al volcador generico le daba igual —pintaba lo
            // guardado— pero el parcial recorre el catalogo congelado, que es
            // lo correcto (una pregunta sin contestar tiene que salir en el
            // papel), y las dos respuestas caian al bloque de «fuera del
            // catalogo». La fixture era la incoherente.
            'config' => ['questions' => [
                'Detente y piensa antes de actuar', 'El area esta senalizada',
            ], 'answers' => ['Si', 'No']],
        ]);
        $campoFoto = $seccion->fields()->create([
            'code' => 'foto_del_area', 'field_type' => 'photo', 'position' => 3,
            'config' => ['max_files' => 2],
        ]);

        app(FormSubmissionService::class)->adjuntar(
            $entrega, $this->imagenPng(300, 200), 'image/png', $campoFoto->id,
        );

        app(FormSubmissionService::class)->responder($entrega, [
            ['code' => 'epp_real', 'row' => 0, 'value' => [
                'person_slug' => 'aBcDeFgHiJkLmNoPqRsTuV',
                'person_name' => 'Ana Quispe',
                'person_doc'  => '40000001',
                'items'       => [
                    ['item' => 'Casco', 'answer' => 'Conforme'],
                    ['item' => 'Guantes', 'answer' => 'No conforme'],
                ],
                'conforme'           => false,
                'correction_measure' => 'Se entrega guante nuevo',
            ]],
            // La fila que el usuario quito llega como lapida: no se imprime.
            ['code' => 'epp_real', 'row' => 1, 'value' => null],
            ['code' => 'preguntas', 'value' => [
                ['question' => 'Detente y piensa antes de actuar', 'answer' => 'Si'],
                ['question' => 'El area esta senalizada', 'answer' => 'No'],
            ]],
        ]);

        $datos = app(FormSubmissionPdfService::class)->datos($entrega->fresh(), $escenario['usuario']);
        $campos = collect($datos['secciones'])->flatMap(fn ($s) => $s['campos'])->keyBy('codigo');

        // ── El EPP ───────────────────────────────────────────────────────
        //
        // Aqui se fijaba el volcado generico, que es lo que el dueño del
        // producto rechazo: se exigia que la cabecera dijera «Person name» y
        // que los items salieran concatenados en una celda. Eran las claves
        // crudas del JSON convertidas en columnas — en ingles, porque son los
        // nombres de las columnas de la base— y por eso su captura del PDF
        // decia «Person id | Legacy plan worker id | Correction measure».
        $epp = $campos['epp_real'];
        $this->assertSame('campo', $epp['render']);
        $this->assertSame('field_work.form_submissions.pdf.campos.person_checklist', $epp['parcial']);

        $this->assertCount(1, $epp['datos']['trabajadores'], 'la fila borrada no se imprime');

        $ana = $epp['datos']['trabajadores'][0];
        $this->assertSame('Ana Quispe', $ana['nombre']);
        $this->assertSame(1, $ana['no_conformes']);

        // Ni un identificador interno en lo que se manda a pintar: se leen las
        // claves que se imprimen y no las que trae el JSON, asi que ya no
        // pueden colarse.
        $serializadoEpp = json_encode($epp['datos']);
        foreach (['person_id', 'person_slug', 'legacy_plan_worker_id', 'item_id'] as $interno) {
            $this->assertStringNotContainsString($interno, $serializadoEpp);
        }

        // ── El banco de preguntas ────────────────────────────────────────
        $preguntas = $campos['preguntas'];
        $this->assertSame('campo', $preguntas['render']);
        $this->assertSame('field_work.form_submissions.pdf.campos.question_bank', $preguntas['parcial']);

        // Un «No» del PTF es una observacion, y el papel lo dice: era lo unico
        // que el documento de la v1 no contaba en ninguna parte.
        $this->assertSame(1, $preguntas['datos']['observaciones']);
        $this->assertSame(
            ['Detente y piensa antes de actuar', 'El area esta senalizada'],
            array_column($preguntas['datos']['preguntas'], 'texto'),
        );

        // La foto de un campo `photo` se pinta dentro de su campo y no vuelve a
        // salir al final como si fuera el papel del formato.
        $foto = $campos['foto_del_area'];
        $this->assertSame('imagenes', $foto['render']);
        $this->assertCount(1, $foto['imagenes']);
        $this->assertStringStartsWith('data:image/', $foto['imagenes'][0]);
        $this->assertSame([], $datos['adjuntos']);
    }

    /** Las caras se leen del disco privado; el PDF no lleva ninguna URL. */
    public function test_la_evidencia_se_incrusta_y_no_se_publica(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $escenario = $this->escenario();

        $datos = app(FormSubmissionPdfService::class)->datos($escenario['entrega'], $escenario['usuario']);

        $conFirma = $this->todasLasFirmas($datos)->filter(fn ($f) => $f['firma'] !== null);

        $this->assertCount(2, $conFirma, 'las dos firmas del plan traen su trazo');
        $conFirma->each(fn ($f) => $this->assertStringStartsWith('data:image/', $f['firma']));

        // Ninguna ruta del disco privado ni URL de evidencia viaja en el documento.
        $serializado = json_encode($datos);
        $this->assertStringNotContainsString('evidencias/', $serializado);
        $this->assertStringNotContainsString('/field_work/evidence', $serializado);

        // Y una firma pendiente sale marcada como tal.
        $this->assertSame(1, $this->todasLasFirmas($datos)->where('pendiente', true)->count());
    }

    /**
     * El documento sale en el idioma **del país del plan**, no en el de quien
     * pulsa el botón.
     *
     * Este PDF no es una pantalla: es el documento de seguridad de un trabajo
     * hecho en un sitio concreto y puede acabar delante de un inspector de ese
     * país. Antes salía en el idioma de la sesión, así que el mismo AST de
     * Lurín era un documento distinto según quién lo descargara.
     */
    public function test_el_pdf_sale_en_el_idioma_del_pais_del_plan(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $escenario = $this->escenario();

        // La sesión en inglés; el plan, de Perú.
        app()->setLocale('en');

        $texto = $this->textoDelPdf(
            app(FormSubmissionPdfService::class)->generar($escenario['entrega'], $escenario['usuario'])->output()
        );

        $this->assertStringContainsString('Reconocimiento facial', $texto);
        $this->assertStringNotContainsString('Face recognition', $texto);

        // Y la petición se queda como estaba: cambiar el idioma a medias
        // dejaría la respuesta siguiente en el idioma equivocado.
        $this->assertSame('en', app()->getLocale());
    }

    /**
     * Si el idioma del país no está traducido, se respeta el de la petición.
     *
     * Sólo hay `es` y `en`. Un plan de Brasil apuntaría a `pt`, y apuntar el
     * documento entero a unas traducciones que no existen devolvería la clave
     * cruda —«work_plans.code»— en cada etiqueta. Un PDF en el idioma de quien
     * lo pide es raro; uno lleno de claves no sirve para nada.
     */
    public function test_si_el_idioma_del_pais_no_esta_traducido_usa_el_de_la_peticion(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $escenario = $this->escenario();

        // Portugués: el país lo pide y la aplicación no lo tiene.
        DB::table('languages')->insertOrIgnore([['id' => 9, 'slug' => Str::random(22), 'name' => 'Portuguese', 'iso_code' => 'pt', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 9, 'slug' => Str::random(22), 'code' => 'pt_BR', 'name' => 'Português', 'language_id' => 9, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->where('id', $escenario['entrega']->workPlan->country_id)
            ->update(['default_locale_id' => 9]);

        app()->setLocale('en');
        $texto = $this->textoDelPdf(
            app(FormSubmissionPdfService::class)->generar($escenario['entrega']->fresh(), $escenario['usuario'])->output()
        );

        $this->assertStringContainsString('Face recognition', $texto);
    }

    /** Los dos idiomas con las mismas claves: si falta una, sale la clave cruda. */
    public function test_las_dos_traducciones_tienen_las_mismas_claves(): void
    {
        $aplanar = function (array $arr, string $prefijo = '') use (&$aplanar) {
            $claves = [];
            foreach ($arr as $k => $v) {
                $claves = array_merge($claves, is_array($v)
                    ? $aplanar($v, $prefijo . $k . '.')
                    : [$prefijo . $k]);
            }

            return $claves;
        };

        $es = $aplanar(require resource_path('lang/es/form_submissions.php'));
        $en = $aplanar(require resource_path('lang/en/form_submissions.php'));

        sort($es);
        sort($en);
        $this->assertSame($es, $en);
    }

    /** La ruta pide permiso, como el resto del modulo. */
    public function test_la_ruta_pide_permiso_de_exportacion(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $escenario = $this->escenario();
        $ruta = route('field_work.forms.pdf', $escenario['entrega']->slug);

        // Solo puede ver: no se lleva el documento.
        $this->actingAs($this->usuarioCon(['form_submissions.view']))
            ->get($ruta)
            ->assertRedirect();

        // Y exportar tampoco basta: el PDF lleva dentro las firmas y los DNI
        // completos de la cuadrilla, asi que pide ademas el permiso de datos
        // privados. Sin esto el enmascarado de la pantalla no servia de nada.
        $this->actingAs($this->usuarioCon(['form_submissions.view', 'form_submissions.export']))
            ->get($ruta)
            ->assertRedirect();

        $respuesta = $this->actingAs($this->usuarioCon([
            'form_submissions.view', 'form_submissions.export', 'people.view_private_info',
        ]))->get($ruta);

        $respuesta->assertOk();
        $respuesta->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $respuesta->getContent());
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    /**
     * Sin `people.view_private_info` no hay firma ni documento entero.
     *
     * Es la misma regla que en pantalla y en la ficha del plan, aplicada al
     * papel: super y admin lo ven, el resto no. Y se aplica en el SERVICIO y no
     * en la plantilla, que es lo que importa — un PDF se descarga y se
     * reenvia, y taparlo al pintar dejaria el numero entero en cualquier sitio
     * donde el dato se pase sin pintarlo.
     *
     * La firma no se sustituye por «Sin firma»: la firma existe, lo que pasa
     * es que este lector no la ve. Decir lo contrario seria mentir en un
     * documento de seguridad.
     */
    public function test_sin_permiso_no_salen_ni_la_firma_ni_el_documento_entero(): void
    {
        $escenario = $this->escenario();

        $deCampo = $this->usuarioCon(['form_submissions.view', 'form_submissions.export']);

        $datos = app(FormSubmissionPdfService::class)->datos($escenario['entrega'], $deCampo);

        $this->assertNotEmpty($this->todasLasFirmas($datos));

        foreach ($this->todasLasFirmas($datos) as $firma) {
            $this->assertNull($firma['firma'], 'la firma trazada no sale sin permiso');
            $this->assertMatchesRegularExpression('/^\*+\d{2}$/', (string) $firma['documento'],
                'el documento sale enmascarado menos los dos ultimos digitos');
        }
    }

    /**
     * El rol solo donde informa: en los aprobadores.
     *
     * En la tabla de trabajadores era quince veces «Trabajador» gastando ancho
     * —por eso se quito— pero en la de aprobadores dice quien autorizo el
     * trabajo («Supervisor HSE», «Jefe de obra»), que es justo lo que se busca
     * al mirar esa tabla. Son dos tablas y por eso pueden decir cosas distintas.
     */
    public function test_el_rol_solo_sale_en_los_aprobadores(): void
    {
        $escenario = $this->escenario();

        $datos = app(FormSubmissionPdfService::class)->datos($escenario['entrega'], $escenario['usuario']);

        $this->assertNotEmpty($datos['firmas']['trabajadores']);

        foreach ($datos['firmas']['trabajadores'] as $firma) {
            $this->assertNull($firma['rol'], 'la tabla de trabajadores no repite la palabra «Trabajador»');
        }

        foreach ($datos['firmas']['aprobadores'] as $firma) {
            $this->assertNotNull($firma['rol'], 'un aprobador sin rol no dice quien autorizo el trabajo');
        }
    }

    /**
     * El representante de la cuadrilla sale DICHO al lado de su firma, y CON
     * LA EMPRESA.
     *
     * Lo pidio el dueño del producto mirando el papel, en dos tiempos: primero
     * que se distinguiera quien responde por el equipo, y despues que el
     * rotulo dijera POR PARTE DE QUIEN — «debe decir representante de la
     * "xxxx" (nombre corto de la empresa)». El rotulo va SOLO en la tabla de
     * trabajadores —en la de aprobadores el papel de cada firma ya lo dice su
     * rol— y solo en la fila del designado.
     */
    public function test_el_representante_sale_dicho_junto_a_su_firma(): void
    {
        $escenario = $this->escenario();

        $datos = app(FormSubmissionPdfService::class)->datos($escenario['entrega'], $escenario['usuario']);

        $deTrabajadores = collect($datos['firmas']['trabajadores']);
        $rotulo = $deTrabajadores->first(fn ($f) => filled($f['representante']))['representante'] ?? null;

        $this->assertNotNull($rotulo, 'la firma de la representante tiene que llevar su rotulo');
        $this->assertStringContainsString(
            $escenario['entrega']->workPlan->company->name,
            $rotulo,
            'el rotulo dice por parte de que empresa responde, con su nombre corto',
        );

        foreach ($datos['firmas']['aprobadores'] as $firma) {
            $this->assertNull($firma['representante'] ?? null,
                'en la tabla de aprobadores el papel ya lo dice el rol');
        }

        // Y la plantilla pinta el rotulo que llega armado del servicio.
        $parcial = file_get_contents(resource_path('views/field_work/form_submissions/pdf/firmas.blade.php'));

        $this->assertStringContainsString("\$firma['representante']", $parcial);
    }

    /**
     * Sin logo, el membrete lleva el nombre del workspace.
     *
     * Es la otra mitad de la regla: el logo manda, pero un workspace que
     * todavia no ha subido ninguno no puede sacar el membrete en blanco.
     */
    public function test_sin_logo_el_membrete_dice_el_nombre(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        app()->setLocale('es');

        $escenario = $this->escenario();

        \App\Models\Tenant::withoutGlobalScopes()->where('id', 1)->update(['logo' => null]);

        $texto = $this->textoDelPdf(
            app(FormSubmissionPdfService::class)->generar($escenario['entrega'], $escenario['usuario'])->output(),
        );

        $this->assertStringContainsString('Contratistas del Sur', $texto);
    }

    /**
     * Las firmas, en un solo saco.
     *
     * Vienen repartidas en trabajadores, aprobadores y la entrega en si porque
     * son tres tablas distintas en el papel; lo que se comprueba en varias de
     * estas pruebas es de todas a la vez.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function todasLasFirmas(array $datos): \Illuminate\Support\Collection
    {
        return collect($datos['firmas'])->flatten(1);
    }

    protected function usuarioCon(array $permisos): User
    {
        $rol = Role::firstOrCreate(
            ['name' => 'rol_' . Str::random(8), 'guard_name' => 'web'],
            ['description' => 'Rol de prueba'],
        );
        $rol->syncPermissions($permisos);

        $usuario = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $usuario->assignRole($rol);

        return $usuario;
    }

    /**
     * Un dia de obra completo: el plan, la cuadrilla, el formato lleno con
     * campos simples y compuestos, y las firmas con su evidencia.
     */
    protected function escenario(): array
    {
        $base = ['country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];

        // Con `people.view_private_info`: quien baja el informe de su plan es el
        // admin del workspace, y es el unico que ve la firma trazada y el
        // documento entero. El caso contrario tiene su propia prueba.
        $usuario = $this->usuarioCon([
            'form_submissions.view', 'form_submissions.export', 'people.view_private_info',
        ]);

        // Membrete: logo en el disco publico y dos slots de firma formal.
        $tenant = Tenant::find(1);
        Storage::disk('public')->put('branding/logo.png', $this->imagenPng(120, 60));
        $tenant->forceFill(['logo' => 'branding/logo.png'])->save();

        ReportSigner::create([
            'tenant_id' => 1, 'user_id' => null, 'name' => 'Ing. Maria Torres',
            'title' => 'Jefa de Seguridad', 'relation' => 'approved', 'sort_order' => 1,
        ]);
        ReportSigner::create([
            'tenant_id' => 1, 'user_id' => $usuario->id, 'name' => null,
            'title' => 'Supervisor de obra', 'relation' => 'prepared', 'sort_order' => 2,
        ]);

        $empresa = Company::create($base + [
            'slug' => Str::random(22), 'num_doc' => '20481234567',
            'name' => 'Electro Andina', 'complete_name' => 'Electro Andina SAC', 'is_active' => true,
        ]);

        $trabajadora = Person::create($base + [
            'slug' => Str::random(22), 'doc_type' => 'DNI', 'num_doc' => '40000001',
            'name' => 'Ana', 'lastname' => 'Quispe',
        ]);
        $supervisor = Person::create($base + [
            'slug' => Str::random(22), 'doc_type' => 'DNI', 'num_doc' => '40000002',
            'name' => 'Luis', 'lastname' => 'Ramos',
        ]);

        $tipo = WorkType::create($base + ['slug' => Str::random(22), 'code' => 'MTTO']);
        $lugar = WorkLocation::create($base + ['slug' => Str::random(22), 'name' => 'Subestacion Lurin']);

        $plan = WorkPlan::create($base + [
            'slug' => Str::random(22), 'company_id' => $empresa->id, 'work_type_id' => $tipo->id,
            'work_location_id' => $lugar->id, 'user_id' => $usuario->id,
            // La representante de la cuadrilla es la trabajadora que firma: es
            // lo que permite probar que su rotulo sale al lado de SU firma.
            'crew_representative_person_id' => $trabajadora->id,
            'code' => 'PE26-0807-0001', 'num_os' => '08072026',
            'description' => 'Mantenimiento preventivo de celda de media tension',
            'date_start' => today(),
        ]);

        $enPlan = WorkPlanPerson::create([
            'slug' => Str::random(22), 'work_plan_id' => $plan->id, 'person_id' => $trabajadora->id,
        ]);

        $regla = ApprovalRule::create($base + [
            'slug' => Str::random(22), 'approver_role' => PersonRole::SUPERVISOR,
            'priority_level' => 1, 'is_required' => true,
        ]);
        $aprobacion = WorkPlanApproval::create([
            'slug' => Str::random(22), 'work_plan_id' => $plan->id,
            'approval_rule_id' => $regla->id, 'person_id' => $supervisor->id,
        ]);

        [$plantilla, $entrega] = $this->formatoLleno($plan, $base);

        // Firma reconocida del trabajador.
        $this->firma($enPlan, $trabajadora, PersonRole::WORKER, [
            'method' => SignatureEvent::FACE_RECOGNITION, 'used_ai' => true,
            'match_distance' => 0.3812, 'threshold_used' => 0.5,
        ]);

        // Firma del supervisor que no reconocio a tiempo: se capturo igual y
        // queda pendiente de revision. Es la que tiene que salir marcada.
        $this->firma($aprobacion, $supervisor, PersonRole::SUPERVISOR, [
            'method' => SignatureEvent::TIMEOUT_CAPTURE, 'used_ai' => false,
            'threshold_used' => 0.5, 'pending_review' => true,
        ]);

        return compact('usuario', 'plan', 'plantilla', 'entrega');
    }

    /** El formato AST: campos simples, matriz de riesgo y checklist por persona. */
    protected function formatoLleno(WorkPlan $plan, array $base): array
    {
        // Con nombre Y sigla, que no son lo mismo: la cabecera del PDF tiene
        // que enseñar el nombre —es el titulo del documento— y dejar la sigla
        // pequeña al lado de la version.
        $plantilla = FormTemplate::create($base + [
            'slug' => Str::random(22), 'code' => 'AST', 'kind' => FormTemplate::STRUCTURED,
            'name' => 'Analisis de Seguridad en el Trabajo',
            'name_es' => 'Analisis de Seguridad en el Trabajo',
            'status' => 'published', 'version' => 1, 'requires_signature' => true, 'published_at' => now(),
        ]);

        $s1 = FormSection::create(['form_template_id' => $plantilla->id, 'position' => 1]);
        $s2 = FormSection::create(['form_template_id' => $plantilla->id, 'position' => 2]);

        $campos = [
            ['s' => $s1, 'code' => 'actividad',   'field_type' => 'text',     'position' => 1, 'is_required' => true],
            ['s' => $s1, 'code' => 'personal',    'field_type' => 'number',   'position' => 2],
            ['s' => $s1, 'code' => 'fecha',       'field_type' => 'date',     'position' => 3],
            ['s' => $s1, 'code' => 'charla',      'field_type' => 'checkbox', 'position' => 4],
            ['s' => $s1, 'code' => 'epp_basico',  'field_type' => 'multiselect', 'position' => 5,
             'config' => ['options' => ['Casco', 'Guantes', 'Botas']]],
            ['s' => $s2, 'code' => 'matriz_de_riesgo', 'field_type' => 'risk_matrix', 'position' => 1,
             'config' => ['severities' => 5, 'probabilities' => 5]],
            ['s' => $s2, 'code' => 'epp_por_trabajador', 'field_type' => 'person_checklist', 'position' => 2,
             'config' => ['items' => ['Casco', 'Guantes']]],
        ];

        $creados = [];
        foreach ($campos as $campo) {
            $seccion = $campo['s'];
            unset($campo['s']);
            $creados[$campo['code']] = $seccion->fields()->create($campo);
        }

        // Se llena y DESPUES se cierra, que es el orden en que pasa en obra.
        // Estaba al reves —nacia confirmada y se rellenaba luego— y colaba
        // porque el candado de «confirmado» vivia solo en la pantalla. Ahora lo
        // aplica el servicio, asi que el atajo ya no existe ni aqui.
        $entrega = FormSubmission::create($base + [
            'slug' => Str::random(22), 'work_plan_id' => $plan->id,
            'form_template_id' => $plantilla->id, 'template_version' => $plantilla->version,
            'status' => 'draft',
            'observations' => 'Sin incidencias durante la jornada.',
        ]);

        app(FormSubmissionService::class)->responder($entrega, [
            ['code' => 'actividad',  'value' => 'Limpieza de celda 3'],
            ['code' => 'personal',   'value' => 4],
            ['code' => 'fecha',      'value' => today()->toDateString()],
            ['code' => 'charla',     'value' => true],
            ['code' => 'epp_basico', 'value' => ['Casco', 'Guantes']],
            // La fila entera, con la consecuencia incluida: le faltaba `riesgo`
            // y colaba porque el servidor solo exigia severidad y probabilidad.
            ['code' => 'matriz_de_riesgo', 'value' => [
                'actividad'    => 'Trabajo en altura',
                'peligro'      => 'Caida a distinto nivel',
                'riesgo'       => 'Fracturas y politraumatismos',
                'control'      => 'Arnes anclado',
                'severidad'    => 4,
                'probabilidad' => 2,
            ]],
            ['code' => 'epp_por_trabajador', 'value' => [
                ['trabajador' => 'Ana Quispe', 'casco' => 'Conforme', 'guantes' => 'Conforme'],
                ['trabajador' => 'Luis Ramos', 'casco' => 'Conforme', 'guantes' => 'No conforme'],
            ]],
        ]);

        $entrega->update(['status' => 'confirmed', 'submitted_at' => now()]);

        return [$plantilla, $entrega->fresh(['answers'])];
    }

    /** La "HOJA X": el formato existe en papel y solo se le toma una foto. */
    protected function hojaX(WorkPlan $plan): FormSubmission
    {
        $plantilla = FormTemplate::create([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1,
            'code' => 'HOJA-X', 'kind' => FormTemplate::UPLOAD_ONLY, 'status' => 'published',
            'version' => 1, 'requires_signature' => true, 'published_at' => now(),
        ]);

        $entrega = FormSubmission::create([
            'slug' => Str::random(22), 'work_plan_id' => $plan->id, 'tenant_id' => 1, 'created_by' => 1,
            'form_template_id' => $plantilla->id, 'template_version' => 1, 'status' => 'draft',
        ]);

        // Igual que arriba: primero la foto del papel, y cerrar es lo ultimo.
        app(FormSubmissionService::class)->adjuntar(
            $entrega, $this->imagenPng(600, 800), 'image/png',
        );

        $entrega->update(['status' => 'confirmed', 'submitted_at' => now()]);

        return $entrega->fresh(['attachments']);
    }

    protected function firma($firmable, Person $persona, string $rol, array $extra): SignatureEvent
    {
        $evento = SignatureEvent::create($extra + [
            'signable_type' => $firmable->getMorphClass(), 'signable_id' => $firmable->getKey(),
            'person_id' => $persona->id, 'role_signed' => $rol, 'signed_at' => now(),
            'tenant_id' => 1,
        ]);

        // WebP, que es lo que deja SignatureService tras comprimir la captura.
        $ruta = 'evidencias/' . now()->format('Y/m') . '/' . Str::random(24) . '.webp';
        $binario = $this->imagenWebp(160, 160);
        Storage::disk('local')->put($ruta, $binario);

        EvidenceFile::create([
            'signature_event_id' => $evento->id, 'kind' => EvidenceFile::FACE,
            'file_path' => $ruta, 'sha256' => hash('sha256', $binario),
            'byte_size' => strlen($binario), 'width' => 160, 'height' => 160, 'taken_at' => now(),
        ]);

        // Y su firma trazada, que es lo que el PDF pinta al lado del nombre.
        // Se dibuja una vez por persona y se reutiliza en todos sus planes, asi
        // que aqui tambien: `firstOrCreate` y no `create`.
        \App\Models\PersonSignature::firstOrCreate(
            ['person_id' => $persona->id],
            (function () use ($persona) {
                $trazo = 'firmas/' . now()->format('Y/m') . '/' . Str::random(24) . '.png';
                Storage::disk('local')->put($trazo, $this->imagenPng(240, 80));

                return [
                    'file_path' => $trazo, 'sha256' => hash('sha256', $persona->id . 'firma'),
                    'source' => 'drawn', 'valid_from' => now(),
                ];
            })(),
        );

        $firmable->forceFill(['is_approved' => true])->save();

        return $evento;
    }

    protected function imagenPng(int $ancho, int $alto): string
    {
        return $this->imagen($ancho, $alto, fn ($img) => imagepng($img));
    }

    protected function imagenWebp(int $ancho, int $alto): string
    {
        return $this->imagen($ancho, $alto, fn ($img) => imagewebp($img, null, 70));
    }

    protected function imagen(int $ancho, int $alto, callable $codificar): string
    {
        $img = imagecreatetruecolor($ancho, $alto);
        imagefilledrectangle($img, 0, 0, $ancho, $alto, imagecolorallocate($img, 200, 210, 220));
        imagefilledellipse($img, (int) ($ancho / 2), (int) ($alto / 2), (int) ($ancho / 2), (int) ($alto / 2),
            imagecolorallocate($img, 70, 90, 110));

        ob_start();
        $codificar($img);
        $salida = ob_get_clean();
        imagedestroy($img);

        return $salida;
    }

    /**
     * Texto plano de un PDF de DomPDF.
     *
     * Los flujos van comprimidos con zlib, asi que no vale con buscar en los
     * bytes: hay que inflarlos primero. Se comprueba sobre el PDF de verdad y
     * no sobre el HTML intermedio porque lo que se archiva es el PDF.
     */
    protected function textoDelPdf(string $pdf): string
    {
        $texto = '';

        if (preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $coincidencias)) {
            foreach ($coincidencias[1] as $bruto) {
                $plano = @gzuncompress($bruto);
                $texto .= ($plano === false ? $bruto : $plano);
            }
        }

        return $texto;
    }
}
