<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Empresas es per-tenant: cada workspace tiene las suyas.
 *
 * El modelo usaba el trait de los catalogos compartidos, asi que una empresa
 * creada por el super quedaba con `tenant_id` NULL y se veia desde todos los
 * workspaces. Al pasar a `BelongsToTenant` esas filas dejan de verse para todo
 * el que no sea super — no se borran, pero desaparecen de la pantalla, que es
 * peor que borrarlas porque nadie se entera.
 *
 * Aqui se adoptan. Con un solo workspace la respuesta es obvia y se hace sola.
 * Con varios NO se adivina: repartir contratistas entre workspaces equivocados
 * es un error que luego no se ve. En ese caso la migracion para y dice
 * exactamente que filas hay que colocar.
 */
return new class extends Migration
{
    public function up(): void
    {
        $huerfanas = DB::table('companies')->whereNull('tenant_id')->count();

        if ($huerfanas === 0) {
            return;
        }

        $tenants = DB::table('tenants')->orderBy('id')->pluck('id');

        if ($tenants->count() === 1) {
            DB::table('companies')->whereNull('tenant_id')->update(['tenant_id' => $tenants->first()]);

            return;
        }

        $ids = DB::table('companies')->whereNull('tenant_id')->orderBy('id')->pluck('id')->implode(', ');

        throw new RuntimeException(
            "Hay {$huerfanas} empresa(s) sin workspace y {$tenants->count()} workspaces, "
            . "asi que no se puede adivinar a cual va cada una. Asignalas a mano y vuelve "
            . "a correr la migracion:\n"
            . "  UPDATE companies SET tenant_id = <id> WHERE id IN ({$ids});\n"
            . "Sin eso quedarian invisibles para todos menos para el super."
        );
    }

    public function down(): void
    {
        // No hay vuelta atras: no se guarda cuales estaban sin asignar, y
        // devolverlas a NULL las volveria a mostrar en todos los workspaces.
    }
};
