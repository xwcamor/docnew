<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * LaboratoriesSeeder — sin datos de fábrica.
 *
 * Laboratorios es un catálogo PER-TENANT (trait BelongsToTenant): cada workspace
 * carga los suyos desde la UI y el tenant_id se auto-asigna al crear. Un seeder
 * corre sin usuario autenticado, así que cualquier registro que creara aquí
 * quedaría con tenant_id NULL (global/huérfano) — incorrecto para un catálogo
 * per-tenant. Por eso NO se siembra nada (igual que los catálogos de conmutador).
 */
class LaboratoriesSeeder extends Seeder
{
    public function run(): void
    {
        // Catálogo per-tenant: el admin agrega sus laboratorios desde la UI.
    }
}
