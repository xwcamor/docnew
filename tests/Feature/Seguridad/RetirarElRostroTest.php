<?php

namespace Tests\Feature\Seguridad;

use App\Models\AuditLog;
use App\Models\PersonBiometric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\ArmaUnaFirma;
use Tests\TestCase;

/**
 * Una persona puede pedir que se borre su cara, y ahora se puede hacer.
 *
 * EL AGUJERO QUE CIERRA
 * ---------------------
 * El texto que el trabajador acepta antes de que se le registre la cara dice,
 * con esas palabras, que puede pedir en cualquier momento que se borre. Hasta
 * ahora eso solo se podia hacer entrando a la base por SQL — o sea que la
 * promesa **no era cierta**, y prometer lo que el sistema no sabe hacer es peor
 * que no prometerlo.
 *
 * LAS DOS DECISIONES QUE ESTA PRUEBA FIJA
 * ---------------------------------------
 *  1. **Se borra, no se desactiva.** `is_active = false` habria sido mas comodo
 *     y no vale: los 128 numeros seguirian en la tabla, y lo que se pidio
 *     retirar es el dato, no su disponibilidad.
 *  2. **Las firmas ya dadas no se tocan.** Un documento firmado hace ocho meses
 *     dice que ese dia se reconocio a esa persona por la cara, y eso paso.
 *     Retirar el dato biometrico de hoy no reescribe lo de entonces.
 */
class RetirarElRostroTest extends TestCase
{
    use RefreshDatabase;
    use ArmaUnaFirma;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        $this->sembrarPadresDeFirma();

        Storage::fake('local');
    }

    /** Se borra la fila, no se marca como inactiva. */
    public function test_retirar_el_rostro_lo_borra_de_verdad(): void
    {
        [$usuario, $persona] = $this->conRostro();

        $this->actingAs($usuario)
            ->delete(route('business_management.people.biometric.forget', $persona->slug))
            ->assertRedirect();

        $this->assertSame(0, PersonBiometric::where('person_id', $persona->id)->count(),
            'desactivar no basta: los 128 numeros seguirian en la tabla');
    }

    /**
     * Queda apuntado quien lo hizo, y sin el descriptor dentro.
     *
     * El rastro del borrado es auditoria y tiene que quedar. Lo que no puede
     * quedar es una copia de la cara en `audit_logs` —seria borrar por delante
     * y guardar por detras— y de eso se encarga `PersonBiometric::$auditExclude`.
     */
    public function test_queda_el_rastro_del_borrado_pero_no_la_cara(): void
    {
        [$usuario, $persona] = $this->conRostro();

        $this->actingAs($usuario)
            ->delete(route('business_management.people.biometric.forget', $persona->slug));

        $apunte = AuditLog::where('auditable_type', PersonBiometric::class)
            ->whereIn('event', ['deleted', 'force_deleted'])
            ->latest('id')
            ->first();

        $this->assertNotNull($apunte, 'borrar un dato biometrico tiene que dejar rastro');
        $this->assertSame($usuario->id, $apunte->user_id);
        $this->assertArrayNotHasKey('face_descriptor', $apunte->old_values ?? []);
    }

    /**
     * Lo que esa persona firmo sigue en pie.
     *
     * Es la mitad que hace que esto se pueda hacer sin miedo: si retirar la cara
     * se llevara por delante las firmas, nadie pulsaria el boton y volveriamos a
     * la promesa incumplida.
     */
    public function test_las_firmas_que_ya_dio_no_se_tocan(): void
    {
        [$usuario, $persona] = $this->conRostro();

        $firmas = \App\Models\SignatureEvent::where('person_id', $persona->id)->count();

        $this->actingAs($usuario)
            ->delete(route('business_management.people.biometric.forget', $persona->slug));

        $this->assertSame($firmas, \App\Models\SignatureEvent::where('person_id', $persona->id)->count());
    }

    /** Sin nada que retirar, lo dice; no finge que hizo algo. */
    public function test_sin_rostro_registrado_avisa(): void
    {
        [$usuario, $persona] = $this->conRostro();

        PersonBiometric::where('person_id', $persona->id)->delete();

        $this->actingAs($usuario)
            ->delete(route('business_management.people.biometric.forget', $persona->slug))
            ->assertSessionHas('error');
    }

    /**
     * Y no lo hace cualquiera: hace falta el permiso de editar personas.
     *
     * Lo que se comprueba es que **no se borra nada**. El codigo de respuesta no
     * es 403 sino una redireccion, y es a proposito: el manejador de
     * `bootstrap/app.php` manda al panel con un aviso a quien ya ha entrado, en
     * vez de enseñarle una pagina de error. Lo que importa aqui es la fila, no
     * el numero.
     */
    public function test_sin_permiso_no_se_retira_nada(): void
    {
        [, $persona] = $this->conRostro();

        $miron = \App\Models\User::factory()->create([
            'tenant_id' => $persona->tenant_id, 'country_id' => 1, 'locale_id' => 1,
        ]);
        $miron->givePermissionTo(Permission::firstOrCreate(['name' => 'people.view', 'guard_name' => 'web']));

        $this->actingAs($miron)
            ->delete(route('business_management.people.biometric.forget', $persona->slug));

        $this->assertSame(1, PersonBiometric::where('person_id', $persona->id)->count(),
            'sin permiso de editar personas no se puede borrar un dato biometrico');
    }

    /**
     * La ficha ofrece el botón, y sólo cuando hay algo que retirar.
     *
     * Un botón que únicamente puede fallar es peor que un botón que no está
     * (docs/UI.md §6).
     */
    public function test_la_ficha_ofrece_retirar_el_rostro(): void
    {
        $vista = file_get_contents(resource_path('js/Pages/People/Show.vue'));

        $this->assertStringContainsString('people.biometric.forget', $vista);
        $this->assertStringContainsString("v-if=\"person.has_biometric && can('people.edit')\"", $vista);

        // Y avisa de las dos cosas que hay que saber antes de pulsar.
        $textos = require resource_path('lang/es/people.php');

        $this->assertStringContainsString('no se desactiva', $textos['biometric_forget_confirm']);
        $this->assertStringContainsString('firmas', $textos['biometric_forget_confirm']);
    }

    /**
     * Alguien con la cara registrada y una firma dada.
     *
     * @return array{0:\App\Models\User,1:\App\Models\Person}
     */
    private function conRostro(): array
    {
        [$usuario, $persona] = $this->escenario();

        $usuario->givePermissionTo(Permission::firstOrCreate(['name' => 'people.edit', 'guard_name' => 'web']));

        $this->assertSame(1, PersonBiometric::where('person_id', $persona->id)->count(),
            'el decorado tiene que dejar a la persona enrolada');

        return [$usuario, $persona->fresh()];
    }
}
