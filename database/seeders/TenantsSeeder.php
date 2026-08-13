<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TenantsSeeder — workspaces de prueba para desarrollo.
 *
 * Cada tenant es una "empresa cliente" del SaaS:
 *   - Empresa 1     (id=1) → admin: joe@example.com
 *
 * Cada tenant tiene un system_user invisible asociado al final del seed
 * (creado por TenantSystemUserService). Ese usuario es el dueno de los
 * tokens API de Sanctum: sin el no hay integracion externa posible.
 *
 * Idempotente: usa updateOrInsert por id (el slug se preserva si ya existia).
 */
class TenantsSeeder extends Seeder
{
    public function run(): void
    {
        // El descargo que sale al pie de los PDF firmados.
        //
        // El que había era de un INFORME DE LABORATORIO: hablaba de «las
        // muestras analizadas bajo las condiciones de ensayo», de la
        // «productividad del equipo» y de entes acreditadores. Aquí no se
        // analiza ninguna muestra — lo que se imprime es el registro de una
        // inspección de seguridad firmada en obra— y un descargo que habla de
        // otra cosa no protege de nada: sólo delata que se copió de otro
        // sistema, y eso en el documento que se le enseña a un inspector.
        //
        // Éste dice las cuatro que sí corresponden: qué es el documento (el
        // registro de un momento), de qué depende su validez (las firmas, que
        // se pueden comprobar con el código del pie), qué NO sustituye (el
        // permiso de trabajo y las obligaciones legales) y hasta dónde llega la
        // responsabilidad de quien lo emite.
        //
        // Cada empresa lo ajusta desde Ajustes del workspace: esto es sólo el
        // punto de partida, y no es asesoría legal.
        $disclaimer = fn (string $empresa) => "Este documento es el registro de las condiciones verificadas y de "
            . "lo declarado por los participantes en el momento del trabajo descrito. Su validez depende de las "
            . "firmas registradas, identificables mediante el código de verificación que figura al pie. No sustituye "
            . "al permiso de trabajo ni a las obligaciones legales de seguridad y salud que correspondan. {$empresa} "
            . "no se responsabiliza de las condiciones surgidas con posterioridad a la firma ni del uso de este "
            . "documento fuera del trabajo al que corresponde. Se prohíbe la reproducción total o parcial sin "
            . "autorización previa escrita.";

        $tenants = [
            ['id' => 1, 'name' => 'Empresa 1',     'address' => 'Av. Industrial 1234, Urb. Las Praderas, Lima — Perú'],
        ];

        // Timezone explícito en cada workspace seed. Sin esto, el booted()
        // del modelo intentaria derivarlo del country del creator — pero
        // como insertamos via DB::table() (raw, sin eventos del modelo) el
        // hook no corre. Mejor ser explícitos.
        foreach ($tenants as $t) {
            $fila = DB::table('tenants')->where('id', $t['id'])->first(['slug', 'report_disclaimer']);

            DB::table('tenants')->updateOrInsert(
                ['id' => $t['id']],
                [
                    'slug'              => $fila->slug ?? Str::random(22),
                    'name'              => $t['name'],
                    'address'           => $t['address'],
                    'report_disclaimer' => $this->descargo($fila->report_disclaimer ?? null, $disclaimer($t['name'])),
                    'is_active'         => true,
                    'timezone'          => 'America/Lima',
                    'created_by'        => 1,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]
            );
        }

        // Reset auto-increment para que el proximo INSERT continue despues del ultimo id.
        if (config('database.default') === 'pgsql') {
            DB::statement("SELECT setval('tenants_id_seq', COALESCE((SELECT MAX(id) FROM tenants), 0) + 1, false)");
        }

        $this->command?->info('Tenant sembrado: Empresa 1 (id=1). Es el unico: los de demostracion se quitaron.');

        // System users — invisibles, duenos de los tokens API. Idempotente.
        $service = app(\App\Services\SystemManagement\TenantSystemUserService::class);
        foreach (\App\Models\Tenant::all() as $tenant) {
            $service->ensureFor($tenant);
        }
        $this->command?->info('System users created/linked for all tenants.');
    }

    /**
     * El descargo a escribir: el nuevo, salvo que alguien haya puesto el suyo.
     *
     * Este seeder corre cada vez que se hace `setup:project --datos`, y hasta
     * ahora machacaba `report_disclaimer` sin mirar. El descargo se edita desde
     * Ajustes del workspace, asi que eso significa que el texto legal que una
     * empresa escribio para SUS informes desaparecia la siguiente vez que
     * alguien resembrara, sin decir nada — y no se nota hasta que sale impreso.
     *
     * Lo que hay guardado se respeta, con una excepcion: el descargo de
     * laboratorio que sembraba este mismo fichero antes. Ese no lo escribio
     * nadie, lo pusimos nosotros y estaba mal —hablaba de muestras de ensayo en
     * un registro de inspeccion de seguridad— asi que se cambia por el que
     * corresponde. Se reconoce por su frase mas caracteristica.
     */
    private function descargo(?string $guardado, string $nuevo): string
    {
        if (blank($guardado)) {
            return $nuevo;
        }

        $delLaboratorio = str_contains($guardado, 'muestras analizadas bajo las condiciones de ensayo');

        return $delLaboratorio ? $nuevo : $guardado;
    }
}
