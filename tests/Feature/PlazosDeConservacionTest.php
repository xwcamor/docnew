<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Los 90 días de `people`, `work_plans` y `form_templates` no los eligió nadie.
 *
 * Venían clonados del módulo del que se copió este producto, y los tres tenían
 * el mismo número por la misma razón: nadie miró qué había dentro de cada
 * tabla. Dentro hay tres cosas distintas. Un tipo de trabajo o una plantilla de
 * formato es catálogo: si se borra, se vuelve a dar de alta. Una ficha de
 * persona es el nombre y el documento de alguien real. Y un plan de trabajo es
 * la evidencia de una jornada de obra —quién estuvo, qué se verificó, quién lo
 * firmó—: purgarlo no libera espacio, borra la prueba, y si más adelante hay un
 * accidente o una inspección sobre ese día, lo que se puede enseñar es lo que
 * quede ahí.
 *
 * Estas pruebas no fijan un plazo correcto: **no se puede**, depende de la
 * jurisdicción del cliente y ese dato no lo tenemos. Fijan lo que sí es
 * comprobable — que la configuración no vuelva a tratar las tres cosas como si
 * fueran la misma, y que el defecto de las dos que documentan a alguien sea
 * conservar y no destruir mientras la decisión siga pendiente.
 */
class PlazosDeConservacionTest extends TestCase
{
    /**
     * El defecto de fábrica no destruye evidencia.
     *
     * Con la purga desactivada, `app:purge-soft-deleted` salta esos módulos y
     * lo dice en el log de cada noche. Con 90 días, en cambio, un plan borrado
     * por error en marzo era irrecuperable en junio, y nadie lo había decidido.
     */
    public function test_las_fichas_de_persona_y_los_planes_no_se_purgan_por_defecto(): void
    {
        $this->assertSame(0, config('purge.modules.people.days'));
        $this->assertSame(0, config('purge.modules.work_plans.days'));
    }

    /**
     * El catálogo sí tiene plazo, y no es el mismo.
     *
     * La comprobación que importa es la desigualdad: que sean números
     * distintos es justo lo que dejó de ser verdad cuando los tres se clonaron
     * en 90. El valor concreto puede moverlo el cliente por `.env`.
     */
    public function test_el_catalogo_lleva_un_plazo_distinto_del_de_las_personas(): void
    {
        $catalogo = config('purge.modules.form_templates.days');

        $this->assertGreaterThan(0, $catalogo);
        $this->assertNotSame(config('purge.modules.people.days'), $catalogo);

        // Y las reglas de aprobación son catálogo también: definen quién puede
        // firmar, no son la firma. La aprobación firmada vive en otra tabla.
        $this->assertSame($catalogo, config('purge.modules.approver_roles.days'));
        $this->assertSame($catalogo, config('purge.modules.approval_rules.days'));
    }

    /**
     * Los tres plazos se fijan desde el `.env`, sin tocar código.
     *
     * Es la mitad que hace que la decisión pendiente se pueda cerrar: quien
     * despliega pone el número que le den sus abogados y no hay que editar un
     * archivo del repositorio ni volver a desplegar.
     */
    public function test_los_tres_plazos_salen_de_variables_de_entorno(): void
    {
        $fuente = file_get_contents(config_path('purge.php'));

        foreach (['PURGE_DAYS_CATALOG', 'PURGE_DAYS_PERSON_RECORD', 'PURGE_DAYS_WORK_EVIDENCE'] as $variable) {
            $this->assertStringContainsString($variable, $fuente);
        }
    }

    /**
     * Queda escrito que purgar un plan borra la evidencia de una jornada.
     *
     * Suena a prueba de comentarios, y lo es a propósito: el error original no
     * fue elegir mal el número, fue que en el archivo no había nada que
     * avisara de lo que ese número destruye. El siguiente que venga a subirlo o
     * bajarlo tiene que leerlo antes de tocarlo.
     */
    public function test_el_archivo_avisa_de_lo_que_se_pierde_al_purgar_un_plan(): void
    {
        $fuente = file_get_contents(config_path('purge.php'));

        $this->assertStringContainsString('EVIDENCIA DE JORNADA', $fuente);
        $this->assertStringContainsString('jurisdicción del cliente', $fuente);
    }

    /**
     * El historial tiene su propia política, y no cuelga de `modules`.
     *
     * `audit_logs` no tiene `deleted_at`: si alguien lo mete en la lista de
     * módulos creyendo que así se purga, `app:purge-soft-deleted` lo rechaza y
     * la tabla se queda otra vez sin purgar — que es exactamente como llegó
     * hasta aquí.
     */
    public function test_el_historial_tiene_politica_propia_fuera_de_los_modulos(): void
    {
        $this->assertArrayNotHasKey('audit_logs', config('purge.modules'));

        $politica = config('purge.audit_logs');

        $this->assertIsArray($politica);
        $this->assertGreaterThan(0, $politica['redact_after_days']);
        $this->assertGreaterThanOrEqual($politica['days'], $politica['security_days']);
    }
}
