<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Position;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El catalogo que trajo la migracion llega editable.
 *
 * Nacia bloqueado, con candado de nivel 'super'. La idea era una pausa antes de
 * renombrar algo que citan miles de planes firmados; el efecto fue que el
 * cliente recien migrado se encontraba **su propio catalogo intocable** —sus
 * cargos, sus sedes, sus tipos de trabajo— con el mensaje de que lo habia
 * bloqueado un administrador del sistema, porque `canBeUnlockedBy()` solo deja
 * a un admin quitar los candados de nivel 'tenant'.
 *
 * Se comprueba con Cargos, que es donde se noto: los 372 trabajadores de la v1
 * vinieron con el suyo, asi que la lista entera salia con candado.
 */
class CatalogoMigradoSinCandadoTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'positions';
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return $this->cargo();
    }

    private function cargo(string $code = 'Tecnico electricista', ?int $legacyId = 7): Position
    {
        return Position::create($this->base() + [
            'code' => $code, 'is_active' => true, 'legacy_id' => $legacyId,
        ]);
    }

    /** Un cargo migrado nace sin candado, asi que el admin lo edita. */
    public function test_un_cargo_migrado_se_puede_editar(): void
    {
        $cargo = $this->cargo();

        $this->assertFalse($cargo->is_locked, 'El catalogo migrado no puede nacer bloqueado.');

        $this->actingAs($this->admin())
            ->put(route('business_management.positions.update', $cargo->slug), [
                'code' => 'Tecnico electricista senior', 'country_id' => 1, 'is_active' => true,
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Tecnico electricista senior', $cargo->fresh()->code);
    }

    /**
     * Y si la base ya venia con el candado puesto, la migracion lo suelta.
     *
     * Aqui se recrea a mano el estado de una base que ya lo tenia: nivel
     * 'super' sobre una fila migrada.
     */
    public function test_un_candado_del_sistema_sobre_lo_migrado_queda_suelto(): void
    {
        $cargo = $this->cargo();

        DB::table('positions')->where('id', $cargo->id)->update([
            'locked_at' => now(), 'locked_by' => 1, 'lock_scope' => 'super',
        ]);

        $this->assertTrue($cargo->fresh()->is_locked);

        $this->soltarCandadosDelSistema();

        $this->assertFalse($cargo->fresh()->is_locked, 'El candado que puso el sistema tiene que soltarse.');
    }

    /**
     * Pero un candado que puso una persona se queda.
     *
     * Es la diferencia que importa: la migracion arregla lo que hizo el sistema
     * sin que nadie lo decidiera, no deshace decisiones de un administrador.
     */
    public function test_un_candado_puesto_a_mano_no_se_toca(): void
    {
        $aMano = $this->cargo('Operador de grua', null);

        DB::table('positions')->where('id', $aMano->id)->update([
            'locked_at' => now(), 'locked_by' => 1, 'lock_scope' => 'tenant',
        ]);

        $this->soltarCandadosDelSistema();

        $this->assertTrue($aMano->fresh()->is_locked, 'Un candado de una persona no lo quita una migracion.');
    }

    /** Lo mismo que hace la migracion `2026_08_13_100000`. */
    private function soltarCandadosDelSistema(): void
    {
        DB::table('positions')
            ->whereNotNull('legacy_id')
            ->whereNotNull('locked_at')
            ->where('lock_scope', 'super')
            ->update(['locked_at' => null, 'locked_by' => null, 'lock_scope' => null]);
    }

    /** El slug lo pone el servicio, no el modelo: aqui se crea a pelo. */
    protected function base(): array
    {
        return parent::base() + ['slug' => Str::random(22)];
    }
}
