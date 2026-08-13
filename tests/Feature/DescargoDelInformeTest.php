<?php

namespace Tests\Feature;

use Database\Seeders\TenantsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El descargo del pie de los PDF: cual se siembra, y a quien no se le pisa.
 *
 * El que habia era de un INFORME DE LABORATORIO —«las muestras analizadas bajo
 * las condiciones de ensayo», la «productividad del equipo», entes
 * acreditadores— en un sistema donde lo que se imprime es el registro de una
 * inspeccion de seguridad firmada en obra. Un descargo que habla de otra cosa
 * no protege de nada: solo delata que se copio de otro sistema, y sale impreso
 * en el documento que se le enseña a un inspector.
 *
 * Y hay una segunda mitad, que es la que de verdad hacia falta cerrar: este
 * seeder corre en cada `setup:project --datos` y machacaba la columna sin
 * mirar. El descargo se edita desde Ajustes del workspace, asi que el texto
 * legal que una empresa escribiera para sus informes desaparecia la siguiente
 * vez que alguien resembrara — en silencio, y sin notarse hasta imprimir.
 */
class DescargoDelInformeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El decorado minimo del seeder.
     *
     * Al final crea el usuario de sistema de cada workspace, que apunta a un
     * pais y un idioma: sin ellos la clave foranea revienta antes de llegar a
     * lo que se prueba aqui.
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => \Illuminate\Support\Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => \Illuminate\Support\Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => \Illuminate\Support\Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => \Illuminate\Support\Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        // Y su rol, que el usuario de sistema se asigna al crearse.
        \Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'api', 'guard_name' => 'web'],
            ['description' => 'Dueño de los tokens de la API'],
        );
    }

    /** Lo que se siembra habla de lo que este sistema documenta. */
    public function test_el_descargo_sembrado_es_de_un_registro_de_obra(): void
    {
        $this->seed(TenantsSeeder::class);

        $texto = DB::table('tenants')->where('id', 1)->value('report_disclaimer');

        // Las cuatro cosas que tiene que decir.
        $this->assertStringContainsString('registro de las condiciones verificadas', $texto);
        $this->assertStringContainsString('Su validez depende de las firmas registradas', $texto);
        $this->assertStringContainsString('No sustituye al permiso de trabajo', $texto);
        $this->assertStringContainsString('reproducción total o parcial', $texto);

        // Y el nombre del workspace dentro, que es de quien es el documento.
        $this->assertStringContainsString('Empresa 1', $texto);

        // Ni una palabra de laboratorio.
        $this->assertStringNotContainsString('muestras analizadas', $texto);
        $this->assertStringNotContainsString('ente acreditador', $texto);
    }

    /**
     * El descargo que escribio el cliente sobrevive a resembrar.
     *
     * Es lo que se rompia: `updateOrInsert` lo reescribia entero cada vez.
     */
    public function test_resembrar_no_pisa_el_descargo_del_cliente(): void
    {
        $this->seed(TenantsSeeder::class);

        $suyo = 'El nuestro, redactado por nuestro asesor legal en 2026.';
        DB::table('tenants')->where('id', 1)->update(['report_disclaimer' => $suyo]);

        $this->seed(TenantsSeeder::class);

        $this->assertSame($suyo, DB::table('tenants')->where('id', 1)->value('report_disclaimer'));
    }

    /**
     * El de laboratorio SI se cambia: ese no lo escribio nadie.
     *
     * Lo puso este mismo seeder y estaba mal. Es la unica excepcion a respetar
     * lo guardado, y por eso se reconoce por su frase y no por «no lo he tocado
     * yo», que no hay forma de saber.
     */
    public function test_el_descargo_de_laboratorio_se_reemplaza(): void
    {
        $this->seed(TenantsSeeder::class);

        DB::table('tenants')->where('id', 1)->update([
            'report_disclaimer' => 'Los resultados de este informe corresponden únicamente a las '
                . 'muestras analizadas bajo las condiciones de ensayo. Empresa 1 no se responsabiliza.',
        ]);

        $this->seed(TenantsSeeder::class);

        $texto = DB::table('tenants')->where('id', 1)->value('report_disclaimer');

        $this->assertStringNotContainsString('muestras analizadas', $texto);
        $this->assertStringContainsString('No sustituye al permiso de trabajo', $texto);
    }
}
