<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Purga el historial de cambios (`audit_logs`) según su política de retención.
 *
 * La tabla no se purgaba nunca. No tiene `deleted_at`, así que
 * `app:purge-soft-deleted` no la miraba siquiera, y en `old_values`/`new_values`
 * guarda copias literales de las filas auditadas: nombres, documentos y
 * teléfonos de personas reales, indefinidamente, incluidos los de quien ya se
 * borró del padrón. Purgar la ficha de una persona y dejar su historial intacto
 * es purgar a medias.
 *
 * Pero borrar el historial entero tampoco vale: un historial vacío no audita
 * nada. La política parte el problema en dos, y está razonada por extenso en
 * `config/purge.php` → bloque «Historial de cambios». En resumen:
 *
 *   1. PODA DEL CONTENIDO — pasado `redact_after_days`, se vacían las dos
 *      columnas JSON y la fila se queda. Desaparece el «de qué a qué», que es
 *      donde están los datos personales; sobrevive el «quién, qué, cuándo y
 *      desde dónde», que es lo que se le pregunta a esta tabla.
 *
 *   2. BORRADO DE LA FILA — con dos plazos: `days` para el rastro corriente y
 *      `security_days`, más largo, para accesos, bloqueos, firmas y
 *      consentimientos. Un `updated` de un catálogo y un `login_lockout` no se
 *      consultan en el mismo plazo ni por el mismo motivo.
 *
 * Los tres plazos se ajustan sin redeploy desde Ajustes; `config/purge.php` es
 * solo el defecto de fábrica. Cascada, de más fuerte a más débil:
 *
 *   Setting (`audit.*`)  >  config/purge.php  >  fase desactivada
 *
 * Cualquiera de los tres en 0 desactiva SU fase y deja las otras dos corriendo.
 *
 * Idempotente: una fila ya podada no se vuelve a podar (se filtra por contenido
 * no nulo), y una borrada ya no está. Corre de madrugada desde
 * `routes/console.php`.
 *
 * Modos:
 *   php artisan app:purge-audit-logs
 *   php artisan app:purge-audit-logs --dry-run
 */
class PurgeAuditLogs extends Command
{
    protected $signature = 'app:purge-audit-logs
        {--dry-run : Solo reporta qué se podaría y qué se borraría, no toca nada.}';

    protected $description = 'Poda el contenido y borra las filas del historial de cambios según la política de retención';

    public function handle(): int
    {
        $politica = config('purge.audit_logs');

        // Sin bloque de política no se inventa uno: borrar historial por
        // defecto es exactamente lo que no debe pasar por un config a medias.
        if (! is_array($politica)) {
            $this->error('Falta el bloque `audit_logs` en config/purge.php. No se toca nada.');

            return self::FAILURE;
        }

        $seco = (bool) $this->option('dry-run');

        $diasPoda        = $this->plazo('audit.redact_payload_days', $politica['redact_after_days'] ?? 0);
        $diasCorrientes  = $this->plazo('audit.retention_days', $politica['days'] ?? 0);
        // El de seguridad nunca por debajo del corriente: si alguien sube la
        // retención general a 5 años y deja la de seguridad en 3, lo que
        // querría decir es «guarda más», no «tira antes los accesos».
        $diasSeguridad   = max(
            $this->plazo('audit.security_retention_days', $politica['security_days'] ?? 0),
            $diasCorrientes,
        );

        // Primero se borra y después se poda, y no al revés: la fila que se va
        // a borrar esta misma noche no merece que antes se le escriba un UPDATE.
        $corrientes = $this->borrarFilas($politica, $diasCorrientes, false, $seco);
        $seguridad  = $this->borrarFilas($politica, $diasSeguridad, true, $seco);
        $podadas    = $this->podarContenido($politica, $diasPoda, $diasCorrientes, $diasSeguridad, $seco);

        $borradas = $corrientes + $seguridad;

        $this->newLine();
        $this->info($seco
            ? "Se podarían {$podadas} filas y se borrarían {$borradas}."
            : "Podadas {$podadas} filas, borradas {$borradas}.");

        // El resumen se anota en el propio historial, igual que hace
        // `app:purge-soft-deleted`. Es un `purged`, así que le toca el plazo de
        // seguridad y su contenido no se poda: el registro de una destrucción
        // de datos es lo único de esta tabla que no se reconstruye con nada.
        if (! $seco && ($podadas > 0 || $borradas > 0)) {
            AuditLog::create([
                'user_id'        => null,
                'event'          => 'purged',
                'auditable_type' => AuditLog::class,
                'auditable_id'   => null,
                'module'         => 'audit_logs',
                'old_values'     => null,
                'new_values'     => [
                    'podadas'         => $podadas,
                    'borradas'        => $borradas,
                    'dias_poda'       => $diasPoda,
                    'dias_corrientes' => $diasCorrientes,
                    'dias_seguridad'  => $diasSeguridad,
                ],
                'note'           => "Purga del historial: {$podadas} podadas, {$borradas} borradas.",
                'created_at'     => now(),
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * El plazo vigente de una fase: manda el ajuste de la BD si está puesto, y
     * si no, el defecto del config.
     *
     * Un ajuste en 0 (o ausente) NO significa «purga inmediata», significa
     * «cae al defecto»; y un defecto en 0 desactiva la fase. Al revés —tomar el
     * 0 como plazo— cualquier ajuste sin sembrar borraría el historial entero
     * la primera noche.
     */
    protected function plazo(string $ajuste, int $defecto): int
    {
        $configurado = Setting::getInt($ajuste, 0);

        return $configurado > 0 ? $configurado : max(0, $defecto);
    }

    /**
     * Fase 1 — vaciar `old_values`/`new_values` de las filas viejas.
     *
     * La fila sobrevive con su quién/qué/cuándo/desde dónde. Se anota la poda
     * en `note` porque, si no, una fila antigua sin valores se lee como «no
     * cambió nada», que es justo lo contrario de lo que pasó.
     *
     * Se le pasan también los dos plazos de borrado para dejar fuera lo que ya
     * cayó en la fase anterior. En una corrida de verdad esas filas ya no
     * están y el filtro no hace nada; en `--dry-run` no se han borrado, y sin
     * el filtro el simulacro las contaría dos veces —una como podada y otra
     * como borrada— justo en el modo cuyo único trabajo es dar un número fiable
     * antes de soltar el comando contra la tabla de producción.
     */
    protected function podarContenido(array $politica, int $dias, int $diasCorrientes, int $diasSeguridad, bool $seco): int
    {
        if ($dias <= 0) {
            $this->line('  <fg=yellow>poda de contenido: desactivada (0 días)</>');

            return 0;
        }

        $corte = now()->subDays($dias);

        $query = AuditLog::query()
            ->where('created_at', '<', $corte)
            ->whereNotIn('event', $politica['keep_payload_events'] ?? [])
            ->where(function ($q) {
                $q->whereNotNull('old_values')->orWhereNotNull('new_values');
            })
            ->where(fn ($q) => $this->lasQueSobrevivenAlBorrado($q, $politica, $diasCorrientes, $diasSeguridad));

        $elegibles = (clone $query)->count();

        if ($elegibles === 0) {
            $this->line("  <fg=gray>poda de contenido: nada elegible (corte: {$corte->toDateString()})</>");

            return 0;
        }

        $this->line("  <fg=cyan>poda de contenido: {$elegibles} filas (corte: {$corte->toDateString()})</>");

        if ($seco) {
            return $elegibles;
        }

        // `note` se compone por fila porque hay que respetar la que ya trae —
        // un motivo de borrado escrito por una persona no se pisa con esto.
        $podadas = 0;
        $marca   = $this->marcaDePoda(now());

        $query->select('id', 'note')
            ->chunkById((int) ($politica['chunk'] ?? 1000), function ($filas) use (&$podadas, $marca) {
                foreach ($filas as $fila) {
                    DB::table('audit_logs')->where('id', $fila->id)->update([
                        'old_values' => null,
                        'new_values' => null,
                        'note'       => trim(((string) $fila->note) . ' ' . $marca),
                    ]);
                    $podadas++;
                }
            });

        return $podadas;
    }

    /**
     * Fase 2 — borrar filas enteras, por uno de los dos plazos.
     *
     * `$deSeguridad` elige el lado: true recorre accesos, bloqueos, firmas y
     * consentimientos; false, todo lo demás. Las dos consultas son
     * complementarias y usan la misma definición, así que ninguna fila se
     * escapa de las dos ni cae en las dos.
     */
    protected function borrarFilas(array $politica, int $dias, bool $deSeguridad, bool $seco): int
    {
        $etiqueta = $deSeguridad ? 'filas de seguridad' : 'filas corrientes';

        if ($dias <= 0) {
            $this->line("  <fg=yellow>{$etiqueta}: borrado desactivado (0 días)</>");

            return 0;
        }

        $corte = now()->subDays($dias);

        $query = AuditLog::query()
            ->where('created_at', '<', $corte)
            ->where(fn ($q) => $this->deSeguridad($q, $politica, $deSeguridad));

        $elegibles = (clone $query)->count();

        if ($elegibles === 0) {
            $this->line("  <fg=gray>{$etiqueta}: nada elegible (corte: {$corte->toDateString()})</>");

            return 0;
        }

        $this->line("  <fg=cyan>{$etiqueta}: {$elegibles} filas (corte: {$corte->toDateString()}, {$dias} días)</>");

        if ($seco) {
            return $elegibles;
        }

        // Por lotes de ids y no con un `delete()` de golpe: en producción esta
        // tabla es la más grande del sistema y un borrado único bloquearía la
        // escritura de auditoría del resto de la aplicación mientras dura.
        $borradas = 0;
        $tamano   = (int) ($politica['chunk'] ?? 1000);

        do {
            $ids = (clone $query)->limit($tamano)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $enEsteLote = DB::table('audit_logs')->whereIn('id', $ids)->delete();
            $borradas  += $enEsteLote;

            // Si un lote no borra nada pero la consulta sigue devolviendo ids,
            // algo va mal y el bucle no avanzaría nunca. Se corta antes de
            // dejar colgado el cron de madrugada.
            if ($enEsteLote === 0) {
                break;
            }
        } while (count($ids) === $tamano);

        return $borradas;
    }

    /**
     * De qué lado cae una fila: seguridad, o rastro corriente.
     *
     * Una sola definición para las dos fases de borrado, y las dos ramas son
     * complementarias exactas: ninguna fila se escapa de las dos ni cae en las
     * dos. Cuenta como de seguridad por el nombre del evento (un acceso, un
     * consentimiento) o por el módulo (una firma, una aprobación), porque ahí
     * el evento se llama `created` como el alta de cualquier catálogo.
     *
     * El `orWhereNull('module')` del lado corriente no es de adorno: `module`
     * es nullable, y en SQL un `NOT IN` contra NULL no da verdadero. Sin él, una
     * fila sin módulo no la reclamaría ninguna de las dos fases y se quedaría
     * en la tabla para siempre, en silencio y con su contenido dentro.
     */
    protected function deSeguridad($q, array $politica, bool $si): void
    {
        $eventos = $politica['security_events'] ?? [];
        $modulos = $politica['security_modules'] ?? [];

        if ($si) {
            $q->whereIn('event', $eventos)->orWhereIn('module', $modulos);

            return;
        }

        $q->whereNotIn('event', $eventos)
            ->where(fn ($m) => $m->whereNotIn('module', $modulos)->orWhereNull('module'));
    }

    /**
     * Las filas que siguen en pie después del borrado de esta noche.
     *
     * Una de seguridad aguanta hasta su corte largo; el resto, hasta el
     * corriente. Un plazo en 0 es esa fase desactivada: ahí no se borra nada,
     * así que ese lado sobrevive entero y no se le pone corte.
     */
    protected function lasQueSobrevivenAlBorrado($q, array $politica, int $diasCorrientes, int $diasSeguridad): void
    {
        $q->where(function ($seguridad) use ($politica, $diasSeguridad) {
            $seguridad->where(fn ($lado) => $this->deSeguridad($lado, $politica, true));

            if ($diasSeguridad > 0) {
                $seguridad->where('created_at', '>=', now()->subDays($diasSeguridad));
            }
        })->orWhere(function ($corriente) use ($politica, $diasCorrientes) {
            $corriente->where(fn ($lado) => $this->deSeguridad($lado, $politica, false));

            if ($diasCorrientes > 0) {
                $corriente->where('created_at', '>=', now()->subDays($diasCorrientes));
            }
        });
    }

    /** La marca que queda en `note` de una fila podada. */
    protected function marcaDePoda(Carbon $cuando): string
    {
        return "[Contenido podado el {$cuando->toDateString()} por política de retención.]";
    }
}
